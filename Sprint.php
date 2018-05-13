<?php
/**
 * Created by PhpStorm.
 * User: sabrokwah
 * Date: 4/28/2016
 * Time: 1:13 PM
 */

namespace OneSource\EDI\Provider\SprintW;

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
use X12\V4010\C811\Segment\IT1;
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
        if($date instanceof ITD) {
            if($date->date_due == "")
            {
                $invoice->date_due = date("Y-m-d", strtotime($transaction->date)+(24*3600*20));
            }
        }


        $invoice->status = 'pending';
        $this->repository->saveInvoice($invoice);

        return $invoice;
    }

    protected function read_account(BIG $transaction, Invoice $invoice)
    {
        $ap = $transaction->find('REF#ref_id_q=12')->first();
        if ($ap instanceof REF) {
            $invoice->account = $ap->ref_id;
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

            $sharedData = 0;
            foreach($transaction->find('SI') as $si){
                if($si instanceof SI && isset($si->services[2]))
                {
                    if(preg_match('/Shared Data/',$si->services[2]))
                    {
                        $sharedData = substr($si->services[2],0,3);
                    }
                }
            }

            //First occurrence of dtm
            $dtm150F = $transaction->find('DTM#date_q=150')->first();
            $dtm151F = $transaction->find('DTM#date_q=151')->first();
            /**
             * @var DTM $dtm150F
             * @var DTM $dtm151F
             */

            //array containing the data plan
            $dataplanHL = array();
            foreach($transaction->find('SI#services[2]=Data Plan') as $dataPlan)
            {
                if($dataPlan instanceof SI)
                {
                    $prevHL = $dataPlan->prev("HL#level=2");
                    if($prevHL instanceof HL)
                    {
                        $dataplanHL[] = $prevHL->id;
                    }
                }
            }

            $dtm150F_date = null;
            $dtm151F_date = null;
            if($dtm150F instanceof DTM)
            {
                $dtm150F_date = $dtm150F->date;
            }

            if($dtm151F instanceof DTM)
            {
                $dtm151F_date = $dtm151F->date;
            }

            $number = $invoice->account;
            $account = $invoice->account;
            $individualsUsage = 0;
            $groups = array();
            $hlSummary = array(
                "h2id" => null,
                "h2name" => null,
                "h2line" => null,

                "h3_id" => null,
                "h3_parent" => null,
                "h3_plan" => null,

                "h4_it1" => 0,
                "h4_id" => null,
                "h4_parent" => null,

                "3rd party Charges" => 0,

                "first occurance of DTM#date_q=150" => $dtm150F_date,
                "first occurance of DTM#date_q=151" => $dtm151F_date,

                "plan name" => null,

                "Shared data plan amount" => $sharedData,
                "hl with dataplans" =>$dataplanHL
            );

            foreach($transaction->find('HL#level=1') as $hl1){
                /**
                 * @var HL  $hl1
                 */
                $this->process_hl($hl1, $invoice,$hlSummary);
            }
            //var_dump($hlSummary['hl with dataplans']);
        }
    }

    protected function process_hl(HL $hl, Invoice $invoice, &$hlSummary)
    {
        $elements = $hl->between($hl, $hl->next('HL'));
        $child_hls = $hl->find('HL#parent=' . $hl->id);
        /**
         * @var HL[] $child_hls
         */
        switch($hl->level)  {
            case '1':
                foreach($elements->find('TXI#rel_code=A') as $tax) {
                    /**
                     * @var TXI $tax
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = $tax->amount;
                    $detail->quantity = 1;
                    $detail->number = $invoice->account;
                    $detail->feature = $this->parse_tax_code($tax->type_code);
                    $detail->code = '1004';
                    $this->repository->saveDetail($detail);
                }
                break;
            case '2':
                $QTYallotted = false;
                $arrayCount = count($hlSummary['hl with dataplans']);

                for($count=0; $count<$arrayCount;$count++)
                {
                    if(isset($hlSummary['hl with dataplans'][$count]))
                    {
                        if($hlSummary['hl with dataplans'][$count] == $hl->id)
                        {
                            $QTYallotted = true;
                            //var_dump("caught. id = ".$hl->id);
                        }
                    }
                }

                $name = $elements->find('NM1')->first();
                $line = $elements->find('SI')->first();
                $planName = "";

                if($line instanceof SI) {
                    //$hlSummary['h2line'] = '('.substr($line->services[0],0,3).') '.substr($line->services[0],3,3).'-'.substr($line->services[0],6,4);
                    $hlSummary['h2line'] = substr($line->services[0],0,3).substr($line->services[0],3,3).substr($line->services[0],6,4);
//                    $planName = $line->services[1];
                }

//                if($name instanceof NM1) {
//                    $hlSummary['h2name']= $name->name;
//                }

                $planNameDesc = array(
                    "AIRAVE2CV" =>"AIRAVE Access Point Coverage",
                    "AIRAVE0RT"=>	"AIRAVE Access Point Retention",
                    "D2121D	Direct"=> "Connect Only Plan",
                    "PDS010L"=>"Bus Advantage Msg & Data 200",
                    "PDS1255E"=>"Data Plan",
                    "PDS3H1886"=>"Data Access 5GB Pool 3",
                    "PDSAH103"=>"Sprint Bus Share Add Smart",
                    "PDSAH1181"=>"Bus Share More Phone Access ( w/ Partial)",
                    "PDSAH1182"=>"Bus Share More Access Tab/MBB (w/ Partial)",
                    "PDSAH560"=>"Sprint Bus Share Add MBB",
                    "PDSAH822"=>"Bus Share More 300GB Line 1",
                    "PDSAH825"=>"Bus Share More Phone Access",
                    "PDSAH826"=>"Bus Share More Access Tab/MBB",
                    "SSBY014LD"=>"Seasonal Standby DC",
                    "SSBY1010M"=>"Seasonal Standby",
                    "SSBY1014D"=>"Seasonal Standby DC",
                    "SSBY1014W"=>"Seasonal Standby 3G/4G CDMA",
                    "SSBY1016C"=>"Seasonal Standby CDMA",
                    "SSBY1016D"=>"Seasonal Standby DC",
                );

                $hlSummary['plan name'] = $planName;


                if($hl->child == 0)
                {
                    if (!Line::count('account', '=', $invoice->account)->and_where('number', '=', $hlSummary['h2line'])->get()) {
                        $ln = new Line;
                        $ln->account = $invoice->account;
                        $ln->number = $hlSummary['h2line'];
                        //$ln->name = $hlSummary['h2name'];
                        $this->repository->saveLine($ln);
                    }
//                    if(isset($planNameDesc[$planName]))
//                    {
//                        $metadata = new Metadata;
//                        $metadata->type = 'invoice.line.planname';
//                        $metadata->target = $invoice->invoice;
//                        $metadata->value = $hlSummary['h2line'];
//                        $metadata->data = $planNameDesc[$planName];
//                        $this->repository->saveMetadata($metadata);
//                        $planName = $planNameDesc[$planName];
//                    }
                }
                else
                {
                    //if (!Line::count('account', '=', $invoice->account)->and_where('number', '=', $hlSummary['h2line'].' '.$hlSummary['h2name'])->get()) {
                    if (!Line::count('account', '=', $invoice->account)->and_where('number', '=', $hlSummary['h2line'])->get()) {
                        $ln = new Line;
                        $ln->account = $invoice->account;
                        //$ln->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                        $ln->number = $hlSummary['h2line'];
                        $this->repository->saveLine($ln);
                    }
//                    if(isset($planNameDesc[$planName]))
//                    {
//                        $metadata = new Metadata;
//                        $metadata->type = 'invoice.line.planname';
//                        $metadata->target = $invoice->invoice;
//                        $metadata->value = $hlSummary['h2line'];
//                        //$metadata->value = $hlSummary['h2line'].' '.$hlSummary['h2name'];
//                        $metadata->data = $planNameDesc[$planName];
//                        $this->repository->saveMetadata($metadata);
//                        $planName = $planNameDesc[$planName];
//                    }
                }

                foreach($elements->find('TXI#rel_code=A') as $tax) {
                    /**
                     * @var TXI $tax
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = $tax->amount;
                    $detail->quantity = 1;

                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature = $this->parse_tax_code($tax->type_code);
                    $detail->code = '1004';
                    $this->repository->saveDetail($detail);
                }
                foreach($elements->find('QTY#qty_unit=MJ') as $minutes) {
                    /**
                     * @var QTY $minutes
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = 0;
                    //$detail->quantity = 1;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature = "Direct Connect Svcs. Minutes/Charges";
                    $detail->usage = $minutes->qty;
                    $detail->allotted = 0;
                    $detail->code = '1002';
                    $this->repository->saveDetail($detail);
                }
                foreach($elements->find('QTY#qty_unit=NF') as $messages) {
                    /**
                     * @var QTY $messages
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = 0;
                    //$detail->quantity = 1;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature = "Number of Messages";
                    $detail->usage = $messages->qty;
                    $detail->allotted = 0;
                    $detail->code = '1002';
                    $this->repository->saveDetail($detail);
                }
                foreach($elements->find('QTY#qty_unit=2P') as $data) {
                    /**
                     * @var QTY $data
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = 0;
                    $detail->quantity = 1;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature =  "Data and Third Party Services KB/Charges";
                    //$detail->usage = $data->qty;
                    $detail->allotted = $hlSummary['Shared data plan amount'];
                    if($QTYallotted == true)
                    {
                        $detail->allotted = 0;
                    }
                    $detail->usage = round($data->qty/(1024*1024),3);
                    $hlSummary['3rd party Charges'] = $hlSummary['3rd party Charges'] + round($data->qty/(1024*1024),3);

                    $detail->code = '1002';
                    $this->repository->saveDetail($detail);
                }
                foreach($elements->find('QTY#qty_unit=AN') as $cell) {
                    /**
                     * @var QTY $cell
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = 0;
                    $detail->quantity = 1;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature =  "Anytime Minutes";
                    $detail->usage = $cell->qty;
                    $detail->code = '1002';
                    $this->repository->saveDetail($detail);
                }
                break;
            case '3':
                break;
            case '4':
//                $it1 = $elements->find('IT1')->first();
//                if($it1 instanceof IT1) {
//                    $hlSummary['h4_id'] = $it1->price;
//                    $detail = new Detail;
//                    $detail->invoice = $invoice->invoice;
//                    $detail->price = $it1->price;
//                    $detail->quantity = 1;
//                    $detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
//                    $detail->feature = "IT1 ";
//                    $detail->code = '1002';
//                    $this->repository->saveDetail($detail);
//                }
                break;
            case '8':
                foreach($elements->find('SLN#rel_code2=I') as $charge) {
                    /**
                     * @var SLN $charge
                     */
                    $next = $charge->next();
                    $detail = new Detail;
                    $detail->code = '1002';
                    $detail->invoice = $invoice->invoice;
                    $detail->quantity = $charge->qty;
                    $detail->price = $charge->price;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    if(trim($detail->number) == "")
                    {
                        $detail->number = $invoice->account;
                    }
                    $da = $charge->next('PID#product_code=DA');
                    $desc = $charge->next('PID');
                    $desc2 = $desc->next();
                    $si = $charge->next();
                    $nextDTM150 = $charge->next('DTM#date_q=150');
                    $nextDTM151 = $charge->next('DTM#date_q=151');
                    /**
                     * @var DTM $nextDTM150
                     * @var DTM $nextDTM151
                     * @var SI $si
                     * @var PID $desc
                     * @var PID $desc2
                     * @var PID $da
                     */



                    if(isset($si->services[0]))
                    {
                        $detail->service_code = $si->services[0];
                    }

                    $detail->feature = $desc->desc;
                    if(isset($si->services[0]) && preg_match('/^EQ[A-Z]{4}$/',$si->services[0]))
                    {
                        if(isset($desc2->desc))
                        {
                            $detail->feature = $detail->feature.' - '.$desc2->desc;
                        }
                        else
                        {
                            if(isset($si->services[2]))
                            {
                                $detail->feature = $detail->feature.' '.$si->services[2];
                            }
                        }
                    }
                    else
                    {
                        if(isset($desc) && isset($si->services[2]))
                        {
                            if($desc->desc != $si->services[2]&& $desc->desc!= "")
                            {
                                $detail->feature = $detail->feature.' - '.$si->services[2];
                            }
                        }
                        if(isset($desc2) && isset($si->services[2]))
                        {
                            //No anytime minutes
                            if($desc2->desc != $si->services[2] && ($desc2->desc != "" && $desc2->desc != "Anytime Minutes"))
                            {
                                $detail->feature = $si->services[2].' - '.$desc2->desc;
                            }
                        }
                    }
                    if(isset($nextDTM150) && isset($nextDTM151))
                    {
                        if(($hlSummary['first occurance of DTM#date_q=150'] != $nextDTM150->date) &&
                            ($hlSummary['first occurance of DTM#date_q=151'] != $nextDTM151->date)
                        )
                        {
                            $detail->feature = $detail->feature.' ('.$invoice->period_start = substr($nextDTM150->date, 4, 2) . '/' . substr($nextDTM150->date, 6, 2) . '/' . substr($nextDTM150->date, 0, 4)
                                .'-'.$invoice->period_start = substr($nextDTM151->date, 4, 2) . '/' . substr($nextDTM151->date, 6, 2) . '/' . substr($nextDTM151->date, 0, 4).') ';
                        }
                    }

                    if(isset($si->services[1]) && $si->services[1] == 'DA')
                    {
                        $detail->service_code = $si->services[1];
                    }

                    $this->repository->saveDetail($detail);
                }
                foreach($elements->find('TXI#rel_code=A') as $tax) {
                    /**
                     * @var TXI $tax
                     */
                    $detail = new Detail;
                    $detail->invoice = $invoice->invoice;
                    $detail->price = $tax->amount;
                    $detail->quantity = 1;
                    $detail->number = $hlSummary['h2line'];
                    //$detail->number = $hlSummary['h2line'].' '.$hlSummary['h2name'];
                    $detail->feature = $this->parse_tax_code($tax->type_code);
                    $detail->code = '1004';
                    $this->repository->saveDetail($detail);
                }
                break;
            case '9':
                break;
            default:
                break;
        }

        foreach($child_hls as $child_hl){
            $this->process_hl($child_hl, $invoice,$hlSummary);
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
        $payee = $transaction->find('N1#entity_code=PR')->first();
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
         * @var \X12\V4010\C811\Segment\N1    $payer
         */

        $payer_info = "";
        $payer_address = "";
        $payer = $transaction->find('N1#entity_code=PR')->first();
        if($payer){
            $payer_address = $payer->name;
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
        $loc = $transaction->find('N1#entity_code=PR')->first();
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
        return array($payer_address,$payer_info, $payee_name, $payee_info, $loc_info);
    }

    protected function process_current_charges(Invoice $invoice){
        $invoice->current_charges = floatval($invoice->trans_value);
    }

    protected function process_billed_adjustment(BIG $transaction, Invoice $invoice){
        $bal = $transaction->find('BAL#code=A#amount_q=NA')->first();
        if($bal instanceof BAL){
            $invoice->billed_adjustment = floatval($bal->amount);
        }
    }
}