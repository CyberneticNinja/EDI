<?php
/**
 * Created by PhpStorm.
 * User: sabrokwah
 * Date: 4/28/2016
 * Time: 1:13 PM
 */

namespace OneSource\EDI\Provider\ATT_Mobility;

use OneSource\EDI\Model\GroupSummary;
use OneSource\EDI\Model\Metadata;
use OneSource\EDI\Import\ImporterAbstract,
    OneSource\EDI\Model\Detail,
    OneSource\EDI\Model\Line,
    OneSource\EDI\Model\Alias,
    OneSource\EDI\Model\Invoice,
    OneSource\EDI\Logger\Logger;


use PHPRecord\Query;
use X12\Parser,
    X12\V4010\C811\Segment\BAL,
    X12\V4010\C811\Segment\N2,
    X12\V4010\C811\Segment\N3,
    X12\V4010\C811\Segment\N4,
    X12\V4010\C811\Segment\NM1,
    X12\V4010\C811\Segment\SLN,
    X12\V4010\C811\Segment\PID,
    X12\V4010\C811\Segment\SI,
    X12\V4010\C811\Segment\BIG;
use X12\V4010\Base\Segment\GS;
use X12\V4010\Base\Segment\IEA;
use X12\V4010\Base\Segment\ISA;
use X12\V4010\C811\Segment\AMT;
use X12\V4010\C811\Segment\HL;
use X12\V4010\C811\Segment\ITA;
use X12\V4010\C811\Segment\ITD;
use X12\V4010\C811\Segment\MSG;
use X12\V4010\C811\Segment\QTY;
use X12\V4010\C811\Segment\REF;
use X12\V4010\C811\Segment\TCD;
use X12\V4010\C811\Segment\TXI;
use X12\V4010\C811\Segment\USD;
use X12\V4010\C811\Segment\DTM;
use X12\V4010\C811\Segment\TDS;
use X12\V4010\C811\Segment\PER;
use X12\Exception;

class Importer extends ImporterAbstract
{
    protected function read_header(BIG $transaction)
    {
        $invoice = new Invoice;
        $invoice->batch = $this->batch;
        $invoice->sender = $this->sender;
        $invoice->date_received = 'now';
        $invoice->date_imported = 'now';
        $invoice->attempts++;

        $dtm = $transaction->find('DTM#date_q=186')->first();
        if ($dtm instanceof DTM) {
            $invoice->period_start = substr($dtm->date, 4, 2) . '/' . substr($dtm->date, 6, 2) . '/' . substr($dtm->date, 0, 4);
        }

        $dtm = $transaction->find('DTM#date_q=187')->first();
        if ($dtm instanceof DTM) {
            $invoice->period_end = substr($dtm->date, 4, 2) . '/' . substr($dtm->date, 6, 2) . '/' . substr($dtm->date, 0, 4);
        }

        $tds = $transaction->find('TDS')->first();
        if ($tds instanceof TDS) {
            $invoice->trans_value = number_format($tds->amount / 100, 2, '.', '');
        }

        $this->process_billed_adjustment($transaction, $invoice);

        $previous_billed_exists = false;
        $bal = $transaction->find('BAL#code=P#amount_q=PB')->first();
        if ($bal instanceof BAL) {
            $previous_billed_exists = true;
            $invoice->previous_billed = floatval($bal->amount);
        }

        $previous_paid_exists = false;
        $bal = $transaction->find('BAL#code=M#amount_q=TP')->first();
        if ($bal instanceof BAL) {
            $previous_paid_exists = true;
            $invoice->previous_paid = floatval($bal->amount);
        }

        $bal = $transaction->find('BAL#code=P#amount_q=PJ')->first();
        if ($bal instanceof BAL) {
            $invoice->balance_forward = floatval($bal->amount);
        } else if ($previous_billed_exists && $previous_paid_exists) {
            $invoice->balance_forward = round($invoice->previous_billed, 2) + round($invoice->previous_paid, 2);
        }

        $this->process_amount_due($transaction, $invoice);

        $this->process_payments($transaction, $invoice);

        $this->process_current_charges($invoice);

        list($payer_info, $payer_address, $payee_name, $payee_info, $loc_info) = $this->process_addresses($transaction, $invoice);

        $invoice->payer = $payer_info;
        $invoice->payer_address = $payer_address;
        $invoice->provider = $payee_name;
        $per = $transaction->find("PER#contact_code=BI")->first();
        if ($per instanceof PER) {
            $invoice->provider_phone = isset($per->number_qs[1]) ? $per->number_qs[1] : '';
        }
        $invoice->payee = $payee_info;
        $invoice->location = $loc_info;
        $invoice->invoice = $transaction->invoice;
        $this->read_account($transaction, $invoice);
        $invoice->date = date("Y-m-d", strtotime($transaction->date));
        /**
         * @var \X12\V4010\C811\Segment\ITD $date
         */
        $date = $transaction->find('ITD')->first();
        $invoice->date_due = $date instanceof ITD ? date("Y-m-d", strtotime($date->date_due)) : '0000-00-00';

        $invoice->status = 'pending';
        $this->repository->saveInvoice($invoice);

        return $invoice;
    }

    protected function process_billed_adjustment(BIG $transaction, Invoice $invoice)
    {
    }

    protected function read_account(BIG $transaction, Invoice $invoice)
    {
        $ap = $transaction->find('REF#ref_id_q=11')->first();
        if ($ap instanceof REF) {
            $invoice->account = $ap->ref_id;
        } else {
            $ma = $transaction->find('REF#ref_id_q=14')->first();
            if ($ma instanceof REF) {
                $invoice->account = ltrim($ma->ref_id, '0');
            }

            /*
            $sa = $transaction->find('REF#ref_id_q=11')->first();
            if($sa instanceof REF){
                $invoice->subaccount = ltrim($sa->ref_id, '0');
                $check_alias = Alias::find('account', '=', $invoice->subaccount)
                    ->and('provider', 'IN', array('0', $this->provider->id()))
                    ->get();
                if($check_alias instanceof Alias && $check_alias->id()){
                    $invoice->subaccount_alias = $check_alias->alias;
                } else {
                    $check_invoice = Invoice::find('subaccount', '=', $invoice->subaccount)
                        ->and_where('subaccount_alias', '!=', '')
                        ->order_by('date', 'desc')
                        ->get();
                    if($check_invoice instanceof Invoice && $check_invoice->id()){
                        $invoice->subaccount_alias = $check_invoice->subaccount_alias;
                    }
                }
            }
            */
        }
        $check_alias = Alias::find('account', '=', $invoice->account)
            ->and('provider', 'IN', array('0', $this->provider->id()))
            ->get();
        if ($check_alias instanceof Alias && $check_alias->id()) {
            $invoice->account_alias = $check_alias->alias;
        } else {
            $check_invoice = Invoice::find('account', '=', $invoice->account)
                ->and_where('account_alias', '!=', '')
                ->order_by('date', 'desc')
                ->get();
            if ($check_invoice instanceof Invoice && $check_invoice->id()) {
                $invoice->account_alias = $check_invoice->account_alias;
            }
        }
    }

    public function process(array $options = array())
    {
        $this->triggerEvent('init');
        $parser = new Parser($this->input, 'V4010', 'C811');
        try {
            $doc = $parser->process();
        } catch (Exception $e) {
            throw $e;
        }
        $this->data['document'] = $doc;
        $transactions = $doc->find('BIG');
        $this->data['total'] = count($transactions);
        $this->data['count'] = 0;
        $this->triggerEvent('prepare');


        foreach ($transactions as $transaction) {
            /**
             * @var \X12\V4010\C811\Segment\BIG $transaction
             */
            $this->data['count']++;
            if (Invoice::count('invoice', '=', $transaction->invoice)->get() || $this->triggerEvent('start', $transaction) === false) {
                $this->logger->log_import(
                    Logger::IMPORT_INVOICE_SKIP,
                    "Skipping import of #" . $transaction->invoice,
                    $transaction->invoice
                );
                //invoice already in database, skip it
                continue;
            }

            $this->logger->log_import(
                Logger::IMPORT_INVOICE_SUCCESS,
                "Started import of #" . $transaction->invoice,
                $transaction->invoice
            );
            $invoice = $this->read_header($transaction);

            //NBS
            $detail = new Detail;
            $detail->invoice = $invoice->invoice;
            $detail->number = $invoice->account;
            $detail->code = '1005';
            $detail->price = 0;
            $invoice->billed_adjustment = 0;
            $bal = $transaction->find('BAL#code=M#amount_q=GS')->first();
            if ($bal instanceof BAL) {
                $detail->feature = "NBS Charges and Credits";
                $detail->price = floatval($bal->amount);
                $this->repository->saveDetail($detail);

            }
            if($invoice->billed_adjustment == 0)
            {
                $bal1 = $transaction->find('BAL#code=P#amount_q=BM')->first();
                if ($bal1 instanceof BAL) {
                    $detail->feature = "NBS Charges and Credits";
                    $detail->price = floatval($bal->amount);
                    $this->repository->saveDetail($detail);
                }
            }

            foreach($transaction->find('SI#service_qs[0]=AE') as $AE) {
                if($AE instanceof SI && $AE->services[0] != $invoice->account) {
                    foreach($AE->find('QTY#qty_q=TO') as $dataUsed) {
                        if($dataUsed instanceof QTY && $dataUsed->qty >0)
                        {
                            $alternate_desc = $dataUsed = $dataUsed->prev('PID#type=X');
                            $alternate_groupservices = $dataUsed->prev('SI');
                            $BANs = $dataUsed->prev('HL#level=2');
                            if($alternate_desc instanceof PID && $alternate_groupservices instanceof SI)
                            {
                                $metadata = new Metadata;
                                $metadata->type = 'invoice.line.planname';
                                $metadata->target = $invoice->invoice;
                                $metadata->value = $AE->services[0].$alternate_groupservices->services[0];
                                $metadata->data = $alternate_desc->desc;
                                $this->repository->saveMetadata($metadata);
                            }
                        }
                    }
                }
            }

            $number = $invoice->account;
            $account = $invoice->account;
            $individualsUsage = 0;
            $groups = array();
            $date = $transaction->find('DTM#date_q=346')->first();
            $hlSummary = array(
                "h3_id" => null,
                "h3_parent" => null,
                "h3_plan" => null,

                "h4_id" => null,
                "h4_parent" => null,
            );
            $isFAN = false;// is a child of a FAN summary

            foreach($transaction->find('HL#level=1') as $hl1){
                /**
                 * @var HL  $hl1
                 */
                $this->process_hl($hl1, $invoice, $number, $isFAN,$account,$individualsUsage,$groups,$date,$hlSummary);
            }

            foreach($groups as $group => $numbers)  {
                foreach($numbers as $number)    {
                    $metadata = new Metadata;
                    $metadata->type = 'invoice.line.group';
                    $metadata->target = $invoice->invoice;
                    $metadata->value = $number;
                    $metadata->data = $group;
                    $this->repository->saveMetadata($metadata);
                }
            }
        }
    }

    protected function process_hl(HL $hl, Invoice $invoice, &$number, &$isFAN,&$account,&$individualsUsage,&$groups,&$date,&$hlSummary)
    {
        $elements = $hl->between($hl, $hl->next('HL'));
        $child_hls = $hl->find('HL#parent=' . $hl->id);
        /**
         * @var HL[] $child_hls
         */
        switch($hl->level)  {
            case '2':
                $number = $invoice->account;
                $ref_11 = $elements->find('REF#ref_id_q=11')->first();
                if($ref_11 instanceof REF) {
                    if($ref_11->ref_id == $invoice->account) {
                        //FAN summary
                        $isFAN = true;
                    }
                }
                else {
                    //not a FAN summary
                    $isFAN = false;
                    $si = $elements->find('SI#service_qs[0]=AE')->first();
                    if($si instanceof SI) {
                        $account = $si->services[0];
                    }
                }
                break;
            case '3':
                //not a FAN summary
                if($isFAN == false) {
                    $si = $elements->find('SI')->first();
                    $ref18 = $elements->find('REF#ref_id_q=18')->first();
                    if($si instanceof SI) {
                        $number = $si->services[0];
                    }
                    $name = $elements->find('NM1')->first();
                    if ($name instanceof NM1 && $name->name != "BILLING ACCOUNT ITEMS") {
                        //individual usage reset
                        $individualsUsage = null;

                        if (!Line::count('account', '=', $invoice->account)->and_where('number', '=', $number)->get()) {
                            $ln = new Line;
                            $ln->account = $invoice->account;
                            $ln->number = $number;
                            $ln->name = $name->name;
                            $this->repository->saveLine($ln);
                        }

                        //Tax
                        foreach($elements->find('TXI#rel_code=I') as $tax){
                            /**
                             * @var TXI $tax
                             */
                            $detail = new Detail;
                            $detail->invoice = $invoice->invoice;
                            $detail->number = $number;
                            $detail->code = '1004';
                            $detail->feature = $this->parse_tax_code($tax->type_code);
                            $detail->price = $tax->amount;
                            $this->repository->saveDetail($detail);
                        }
                    }
                    if($ref18 instanceof REF && !(preg_match('/MOBILE /',$ref18->ref_id))) {
                        if (!isset($groups[$account][$number])) {
                            $groups[$account][$number] = $number;
                        }
                    }
                    if($ref18 instanceof REF) {
                        $hlSummary["h3_id"] = $hl->id;
                        $hlSummary["h3_parent"] = $hl->parent;
                        $hlSummary["h3_plan"] = $ref18->ref_id;

                        //Plan
                        $detail = new Detail;
                        $detail->invoice = $invoice->invoice;
                        $detail->number = $number;
                        $detail->code = '1002';
                        $detail->feature = "PLAN : ".$ref18->ref_id;
                        $detail->price = 0;
                        $this->repository->saveDetail($detail);
                    }
                }
                break;
            case '4':
                //is this a group Summary
                $isGroupNumSum = $elements->find("PID")->first();
                if($isGroupNumSum instanceof PID && preg_match('/MOBILE SHARE/',$isGroupNumSum->desc)) {
                    $hlSummary["h4_id"] = $hl->id;
                    $hlSummary["h4_parent"] = $hl->parent;
                }
                else {
                    $hlSummary["h4_id"] = null;
                    $hlSummary["h4_parent"] = null;
                }
                break;
            case '8':
                //not a FAN summary
                if($isFAN == false) {
                    //adjustment
                    $adjustment = $elements->find('AMT#amount_q=BM')->first();
                    if($adjustment instanceof AMT)  {
                        $detail = new Detail();
                        $detail->code = '1002';
                        $detail->invoice = $invoice->invoice;
                        $detail->price = $adjustment->amount;
                        $detail->quantity = 1;
                        $detail->feature = "Adjustment";
                        $detail->number = $number;
                        $this->repository->saveDetail($detail);
                    }


                    foreach($elements->find('SLN#rel_code2=I') as $charge) {
                        /**
                         * @var SLN $charge
                         */
                        $next = $charge->next();

                        $detail = new Detail;
                        $detail->invoice = $invoice->invoice;
                        $detail->number = $number;
                        $detail->code = '1002';
                        $detail->quantity = $charge->qty;
                        $detail->price = $charge->price;

                        //If there is a a MSG segment
                        $msg = $charge->prev('MSG');

                        while($next) {
                            if ($next instanceof PID && $detail->feature == "") {

                                if($next->desc == "CTN KB CHARGES" && !(preg_match('/MOBILE SHARE/',$hlSummary['h3_plan']))) {
                                    $detail->feature .= $next->desc.' ';
                                }
                                else {
                                    $detail->feature = $next->desc;
                                }
                            }
                            elseif($next instanceof DTM && $date instanceof DTM && $next->date_q ==346
                                && $date->date != $next->date
                            ){
                                $nextDate = $next->next();
                                $detail->feature .= " (".substr($next->date, 4, 2) . '/' . substr($next->date, 6, 2) . '/' . substr($next->date, 0, 4);
                                if($nextDate instanceof DTM){
                                    $detail->feature .= " - ".substr($nextDate->date, 4, 2) . '/' . substr($nextDate->date, 6, 2) . '/' . substr($nextDate->date, 0, 4).')';
                                }
                            }
                            elseif($next instanceof QTY) {
                                //data
                                $detail->usage = $next->qty;
                                if($detail->feature != "CTN KB CHARGES"){
                                    $detail->allotted = 0;
                                }
                                else{
                                    $individualsUsage = $detail;
                                }
                                break;
                            }
                            elseif($next instanceof REF && $next->ref_id_q == "TN") {
                                if(preg_match('/^CAS\s|^CHR\s|^CLA\s|^COM\s|^ELE\s|^HDS\s|^KYB\s|^PHO\s|^SCP\s|^SIM\s/',
                                        $detail->feature)  && !preg_match('/EQUIPMENT ORDER DAT/',$detail->feature)){
                                    $detail->feature = $detail->feature.', '.$next->ref_id;
                                }
                            }
                            elseif($next instanceof MSG) {
                                if(preg_match('/^CAS\s|^CHR\s|^CLA\s|^COM\s|^ELE\s|^HDS\s|^KYB\s|^PHO\s|^SCP\s|^SIM\s/',
                                        $detail->feature) && !preg_match('/EQUIPMENT ORDER DAT/',$detail->feature)
                                ){
                                    $detail->feature = $detail->feature.', '.$charge->products[0].', '.$next->text;
                                }
                            }
                            $next = $next->next();
                        }
                        if($detail->feature != "CTN KB CHARGES") {
                            //text messages voice
                            if($detail->feature == "CTN ROAM GPRS") {
                                $detail->feature = preg_replace('/ KB /'," GB ",$detail->feature);
                                $detail->usage = round($detail->usage/(1024*1024),3);
                            }
                            elseif(preg_match('/MMS|SMS|BUCKET|ROAMING|ROAM|AIR CHARGES|MINS|MIN|MSG|INTERNATIONAL LD CHARGES/',strtoupper(trim($detail->feature))))
                            {
                                //var_dump($detail->feature.' '.$detail->usage);
                            }
                            else{
                                $detail->feature = preg_replace('/ KB /'," GB ",$detail->feature);
                                $detail->usage = round($detail->usage/(1024*1024),3);
                            }
                            $detail->feature = trim($detail->feature);
                            $this->repository->saveDetail($detail);
                        }
                    }

                    if($hlSummary["h4_id"] != null) {
                        //group number

                        $sameParent = $hl->next('HL');
                        $notSameParent = true;
                        if($sameParent instanceof HL && $sameParent->parent == $hl->parent) {
                            $notSameParent = false;
                        }

                        $si = $elements->find('SI')->first();
                        /**
                         * @var SI $si
                         */
                        if(preg_match('/G33/',$si->services[0])) {
                            if (!isset($groups[$account . $si->services[0]][$number])) {
                                $groups[$account . $si->services[0]][$number] = $number;
                            }
                        }
                        //allocation
                        $allocation = $elements->find('PID#type=X')->first();
                        /**
                         * @var PID $allocation
                         */
                        if(preg_match('/Mobile Share/',$allocation->desc)){
                            $allocation = preg_replace('/[\D]/','',$allocation);
                        }
                        else {
                            $allocation = 0;
                        }

                        if($notSameParent == true)
                        {
                            $groupDataUsage = $elements->find('QTY#qty_q=TO')->first();
                            if($groupDataUsage  instanceof QTY && $groupDataUsage->qty != 0)
                            {
                                foreach($elements->find('SI#service_qs[0]=MD') as $data) {
                                    /**
                                     * @var SI $data
                                     */
                                    $group_summary = new GroupSummary;
                                    $group_summary->invoice = $invoice->invoice;
                                    $group_summary->group = $account . $si->services[0];
                                    $group_summary->feature = str_replace(' KB ', ' GB ', $data->services[0]);
                                    $qty = $data->prev();
                                    if($qty instanceof QTY) {
                                        $group_summary->usage = round($qty->qty/(1024 * 1024), 3);
                                    }
                                    $group_summary->allotted = $allocation;
                                    $this->repository->saveGroupSummary($group_summary);
                                }
                            }
                        }

                        //overage
                        $adjustment = $elements->find('AMT#amount_q=0L')->first();
                        if($adjustment instanceof AMT && $adjustment->amount != 0) {
                            $detail = new Detail();
                            $detail->code = '1002';
                            $detail->invoice = $invoice->invoice;
                            $detail->price = $adjustment->amount;
                            $detail->quantity = 1;
                            $detail->feature = "Overage";
                            $detail->number = $number;
                            $this->repository->saveDetail($detail);
                        }

                        if($notSameParent == true) {
                            //Individual usage
                            if((isset($individualsUsage)) && ($individualsUsage instanceof Detail)) {
                                $detail = $individualsUsage;
                                $detail->allotted = $allocation;
                                $gb = $detail->usage;
                                $detail->usage = round($gb/(1024*1024),3);
                                $detail->feature = preg_replace('/ KB /'," GB ",$detail->feature);
                                $this->repository->saveDetail($detail);
                            }
                        }

                    }//End of individuals' details (end)
                }
                break;
            default:
                break;
        }

        foreach($child_hls as $child_hl){
            $this->process_hl($child_hl, $invoice, $number,$isFAN,$account,$individualsUsage,$groups,$date,$hlSummary);
        }
    }

    protected function process_addresses(BIG $transaction, Invoice $invoice)
    {
        /**
         * @var \X12\V4010\C811\Segment\REF $payer
         */

        $payer_info = "";
        $payer_address = "";
        $payer = $transaction->find('REF#ref_id_q=79#desc=BILLING ACCOUNT NAME')->first();
        if ($payer) {
            $payer_address = $payer->ref_id;
        }
        /**
         * @var \X12\V4010\C811\Segment\N1 $payee
         */
        /**
         * @var \X12\V4010\C811\Segment\N3 $payee_address
         */
        /**
         * @var \X12\V4010\C811\Segment\N4 $payee_address2
         */
        $payee_name = "";
        $payee_info = "";
        $payee = $transaction->find('N1#entity_code=PE')->first();
        if ($payee) {
            $payee_name = $payee->name;
            $payee_address = $payee->next();
            if ($payee_address instanceof N3) {
                $payee_info .= $payee_address->address . "\r\n";
                $payee_address2 = $payee_address->next();
                if ($payee_address2 instanceof N4) {
                    $payee_info .= $payee_address2->city . ', ' . $payee_address2->state . ' ' . substr($payee_address2->postal, 0, 5);
                }
            }
        }
        /**
        /**
         * @var \X12\V4010\C811\Segment\REF    $payer
         */

        $payer_info = "";
        $payer_address = "";
        $payer = $transaction->find('REF#ref_id_q=79#desc=BILLING ACCOUNT NAME')->first();
        if($payer){
            $payer_address = $payer->ref_id;
        }
        /**
         * @var \X12\V4010\C811\Segment\N1    $payee
         */
        /**
         * @var \X12\V4010\C811\Segment\N3 $payee_address
         */
        /**
         * @var \X12\V4010\C811\Segment\N4 $payee_address2
         */
        $payee_name = "";
        $payee_info = "";
        $payee = $transaction->find('N1#entity_code=PE')->first();
        if($payee){
            $payee_name = $payee->name;
            $payee_address = $payee->next();
            if($payee_address instanceof N3){
                $payee_info .= $payee_address->address .  "\r\n";
                $payee_address2 = $payee_address->next();
                if($payee_address2 instanceof N4){
                    $payee_info .= $payee_address2->city . ', ' .  $payee_address2->state . ' ' . substr($payee_address2->postal, 0, 5);
                }
            }
        }
        /**
         * @var \X12\V4010\C811\Segment\N1    $loc
         */
        /**
         * @var \X12\V4010\C811\Segment\N3 $loc_address
         */
        /**
         * @var \X12\V4010\C811\Segment\N4 $loc_address2
         */
        $loc_info = "";
        $loc = $transaction->find('N1#entity_code=IA')->first();
        if($loc){
            $loc_address = $loc->next();
            if($loc_address instanceof N3){
                $loc_info .= $loc_address->address .  "\r\n";
                $loc_address2 = $loc_address->next();
                if($loc_address2 instanceof N4){
                    $loc_info .= $loc_address2->city . ', ' .  $loc_address2->state . ' ' . substr($loc_address2->postal, 0, 5);
                }
            } else if($loc_address instanceof N4){
                /**
                 * @var \X12\V4010\C811\Segment\N4 $loc_address
                 */
                $loc_info .= $loc_address->city . ', ' .  $loc_address->state . ' ' . substr($loc_address->postal, 0, 5);
            }
        }
        return array($payer_address, $payer_info, $payee_name, $payee_info, $loc_info);
    }

    protected function process_current_charges(Invoice $invoice){
        $invoice->current_charges = floatval($invoice->trans_value)
            - floatval($invoice->billed_adjustment);
    }
}