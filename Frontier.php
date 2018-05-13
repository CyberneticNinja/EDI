<?php
/**
 * Created by PhpStorm.
 * User: sabrokwah
 * Date: 4/25/2016
 * Time: 2:04 PM
 */

namespace OneSource\EDI\Provider\Frontier;

use OneSource\EDI\Import\ImporterAbstract,
    OneSource\EDI\Model\Detail,
    OneSource\EDI\Model\Line,
    OneSource\EDI\Model\Invoice,
    OneSource\EDI\Logger\Logger;

use OneSource\EDI\Model\InvoiceCharge;
use OneSource\EDI\Model\Alias;
use PHPRecord\DB;

use X12\Parser,
    X12\V4010\C811\Segment\BAL,
    X12\V4010\C811\Segment\N2,
    X12\V4010\C811\Segment\N3,
    X12\V4010\C811\Segment\N4,
    X12\V4010\C811\Segment\NM1,
    X12\V4010\C811\Segment\SLN,
    X12\V4010\C811\Segment\PID,
    X12\V4010\C811\Segment\QTY,
    X12\V4010\C811\Segment\SI,
    X12\V4010\C811\Segment\TCD;
use X12\Segment;
use X12\V4010\C811\Segment\AMT;
use X12\V4010\C811\Segment\HL;
use X12\V4010\C811\Segment\BIG;
use X12\V4010\C811\Segment\IT1;
use X12\V4010\C811\Segment\ITA;
use X12\V4010\C811\Segment\ITD;
use X12\V4010\C811\Segment\MSG;
use X12\V4010\C811\Segment\PER;
use X12\V4010\C811\Segment\REF,
    X12\Exception;
use X12\V4010\C811\Segment\TXI;
use X12\V4010\C811\Segment\USD;
use X12\V4010\C811\Segment\DTM;
use X12\V4010\C811\Segment\TDS;

class Importer extends ImporterAbstract {
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

            $hl_holder = array(
                "hl_1" => null,
                "hl_2" => null,
                "hl_3" => null,
                "hl_4" => null,
                "hl_7" => null,
                "hl_8" => null,
                "number"=>$invoice->account
            );

            foreach($transaction->find('HL#level=1') as $HL)
            {
                $US = array();//usage summary

                /**
                 * @var \X12\V4010\C811\Segment\HL $HL
                 */
                $this->execute_hl($invoice,$HL,$hl_holder,$US);

                for($counterforUS = 0;$counterforUS < sizeof($US); $counterforUS++)
                {
                    $detail = new Detail();
                    $detail->code = '1002';
                    $detail->invoice = $invoice->invoice;
                    $detail->number = $invoice->account;
                    $detail->feature .= $US[$counterforUS]['description'].' Summary, ';
                    $detail->price = $US[$counterforUS]['price'];
                    $detail->feature .= ' Calls :'.$US[$counterforUS]['calls'].'.';
                    $detail->feature .= ' Minutes :'.$US[$counterforUS]['minutes'].'.';
                    $this->repository->saveDetail($detail);
                }
            }
        }
    }

    /**
     * @param Invoice $invoice
     * @param HL $hl
     * @param $hl_holder
     * @param $US
     */
    protected function execute_hl(Invoice $invoice, HL $hl,$hl_holder,&$US)
    {

        $HSI_data = array(
            "CLSLT" => "Broadband Lite - Port & High-Speed Access w/ Static IP",
            "CPSLT" => "Broadband Lite - Port & High-Speed Access w/ Static IP",
            "LDLTL" => "Simply Broadband Lite - Loop & HSI w/ Static IP",
            "5PDLT" => "Simply Broadband Lite - Loop & HSI w/ Static IP",
            "CLSMX" => "Broadband Max - Port & High-Speed Access w/ Static IP",
            "CPSMX" => "Broadband Max - Port & High-Speed Access w/ Static IP",
            "LLSMX" => "Broadband Max - Port & High-Speed Access w/ Static IP",
            "LPSMX" => "Broadband Max - Port & High-Speed Access w/ Static IP",
            "HLSMB" => "HSI Static Bus - Loop & Port",
            "H3MB" => "HSI Static Bus - Loop & Port",
            "H5MB" => "HSI Static Bus - Loop & Port",
            "HLDMB" => "HSI Static Bus - Loop & Port",
            "LLDUL" => "Broadband Ultra Dynamic Plus - Loop & Port",
            "LPDUL" => "Broadband Ultra Dynamic Plus - Loop & Port",
            "LSMXC" => "Simply Broadband Max - Port & High-Speed Access w/ Static IP",
            "SPSMX" => "Simply Broadband Max - Port & High-Speed Access w/ Static IP",
            "UL6SV" => "Broadband Max",
            "UP6SV" => "Broadband Max",
            "FL479" => "FiOS Internet 25/25",
            "FP479" => "FiOS Internet 25/25",
            "FL632" => "FiOS Internet 50M/50M 2Y",
            "FP632" => "FiOS Internet 50M/50M 2Y",
            "DULLC" => "Broadband Ultra Loop & Port",
            "PDULC" => "Broadband Ultra Loop & Port",
            "CLDLT" => "Broadband Lite Loop & Port",
            "CPDLT" => "Broadband Lite Loop & Port",
            "HBL01" => "High Speed Internet with Static IP",
            "HBP01" => "High Speed Internet with Static IP",
            "BDISM" => "Business High Speed Internet",
            "BDLSM" => "Business High Speed Internet",
            "BDIDM" => "Business HSI Dynamic IP",
            "BDLDM" => "Business HSI Dynamic IP",
            "FL634" => "FiOS Internet 50M/50M 2Yr",
            "FP634" => "FiOS Internet 50M/50M 2Yr",
            "BSICV" => "Business High Speed Internet",
            "BSLCV" => "Business High Speed Internet",
            "H3BP3" => "High Speed Internet Pro",
            "H3L3B" => "High Speed Internet Pro",
            "LSULC" => "Simply Broadband Ultra Plus Static",
            "SPSUL" => "Simply Broadband Ultra Plus Static",
            "CLDMX" => "Broadband Max",
            "CPDMX" => "Broadband Max",
            "LDULC" => "Simply Broadband Ultra Dynamic Plus",
            "SPDUL" => "Simply Broadband Ultra Dynamic Plus",
            "DL531" => "High Speed Inet - 7.1/768",
            "DP531" => "High Speed Inet - 7.1/768",
            "LSMXL" => "Simply Broadband Max HSI w/ Static IP",
            "5PSMX" => "Simply Broadband Max HSI w/ Static IP",
            "LDMXC" => "Simply Broadband Max",
            "SPDMX" => "Simply Broadband Max",
            "SLS75" => "FiOS Internet 75M/75M",
            "SPS75" => "FiOS Internet 75M/75M",
            "HL405" => "High Speed Internet - 7.1/768",
            "HP405" => "High Speed Internet - 7.1/768",
            "FL668" => "FiOS Internet 75M/75M 2YR",
            "FP668" => "FiOS Internet 75M/75M 2YR",
            "PSULC" => "Broadband Ultra",
            "SULLC" => "Broadband Ultra",
            "CLSUL" => "Broadband Ultra Plus",
            "CPSUL" => "Broadband Ultra Plus",
            "DL655" => "High Speed Int-7.1/768 2Y",
            "DP655" => "High Speed Int-7.1/768 2Y",
            "LPDLT" => "Broadband Lite Dynamic HSI",
            "LLDLT" => "Broadband Lite Dynamic HSI",
            "CDLV6" => "BHSI Static IP - INET & Acc",
            "CDIV6" => "BHSI Static IP - INET & Acc",
            "BDL8D" => "Business Highspeed Internet",
            "LPSLT" => "Broadband Lite State IP",
            "LLSLT" => "Broadband Lite State IP",
            "FL622" => "FiOS Internet 50M/25M 2Y",
            "FP622" => "FiOS Internet 50M/25M 2Y",
            "HLDHB" => "HSI Dynamic Bus - Loop & Port",
            "H7MB" => "HSI Dynamic Bus - Loop & Port",
        );

        $between_hl = $hl->between($hl,'HL');

        if(strlen($hl_holder['number'])>10 && !preg_match("/[A-Z]+/",$hl_holder['number']))
        {
            $hl_holder["number"] = $hl_holder['number'];
        }
        switch($hl->level)
        {
            case 1:
                $hl_holder['hl_1'] = $between_hl;
                foreach($between_hl->find('TXI') as $tax)
                {
                    if($tax instanceof TXI)
                    {
                        $detail = new Detail();
                        $detail->code = '1004';
                        $detail->invoice = $invoice->invoice;
                        $detail->number = $hl_holder["number"];
                        $detail->feature = $this->execute_desiredDesc($tax->id.'[end]','');
                        $detail->price = $tax->amount;
                        $detail->quantity = 1;
                        $this->repository->saveDetail($detail);
                    }
                }
                break;
            case 2:
                break;
            case 3:
                break;
            case 4:
                $hl_holder['hl_4'] = $between_hl;
                break;
            case 5:
                break;
            case 6:
                break;
            case 7:
                $hl_holder['hl_7'] = $between_hl;
                $serviceCharasteristicID = $between_hl->find('SI')->first();
                if($serviceCharasteristicID instanceof SI)
                {
                    $hl_holder["number"] = $serviceCharasteristicID->services[0];
                    //if a line from this particular account is not found, create a new line and save that
                    if(!Line::count('account', '=', $invoice->account)->and_where('number', '=', $serviceCharasteristicID->services[0])->get()){
                        $line = new Line;
                        $line->account = $invoice->account;
                        $line->number = $hl_holder["number"];
                        $this->repository->saveLine($line);
                    }
                }
                break;
            case 8:
                $hl_holder['hl_8'] = $between_hl;
                $partial = false;
                foreach($between_hl->find('SLN#rel_code2=I') as $sln)
                {
                    if($sln instanceof SLN)
                    {
                        $hl_4 = $hl_holder['hl_4'];

                        /**
                         * @var HL    $hl_4
                         */
                        $measuredServiceTrueFalse = $hl_4->find("PID#desc=Measured Service")->first();
                        $thirdPartyTrueFalse = $hl_4->find("PID#desc=Third Party Charges")->first();

                        if(!$measuredServiceTrueFalse instanceof PID)
                        {
                            $next = $sln->next();
                            $prev = $next;
                            while($next)
                            {
                                if($next instanceof PID)
                                {
                                    break;
                                }
                                else
                                {
                                    $next = $next->next();
                                }
                            }
                            $desc = $hl->next('PID');
                            $detail = new Detail();
                            $detail->code = '1002';
                            $detail->invoice = $invoice->invoice;
                            $detail->number = $hl_holder["number"];

                            if($prev instanceof SI)
                            {
                                $detail->service_code = $prev->services[0];
                                if(($prev->services[0] == "RE10")
                                    || ($prev->services[0] == "RE11")
                                    || ($prev->services[0] == "RE12")
                                    || ($prev->services[0] == "RE13")
                                )
                                {
                                    $detail->number = $invoice->account;
                                }
                            }

                            $detail->feature = $this->execute_desiredDesc($next->desc.'[end]',$detail->service_code);
                            $date = $hl->next('DTM');
                            if ($date instanceof DTM){
                                if ($date->date_q == 154){
                                    $detail->start = date('m/d/Y', strtotime(substr($date->date, 0, 4) . '-' . substr($date->date, 4, 2) . '-' . substr($date->date, 6, 2)));
                                    $nextDTM = $date->next();
                                    if ($nextDTM instanceof DTM){
                                        if ($nextDTM->date_q == 155){
                                            $detail->end = date('m/d/Y', strtotime(substr($nextDTM->date, 0, 4) . '-' . substr($nextDTM->date, 4, 2) . '-' . substr($nextDTM->date, 6, 2)));
                                            $partial = true;
                                        }
                                        if ($partial) {
                                            $detail->feature .= ' (Partial Month Charges)';
                                        }
                                    }
                                }
                            }
                            //third party charges
                            if($thirdPartyTrueFalse instanceof PID)
                            {
                                $detail->feature .= " - Third Party Charge ";
                                $hl_1 = $hl_holder['hl_1'];
                                /**
                                 * @var HL    $hl_1
                                 */
                                $name = $hl_1->find("NM1")->first();
                                $telephone = $hl_1->find("PER")->first();
                                if($name instanceof NM1 && $telephone instanceof PER)
                                {
                                    $detail->feature .= $name->name.' - '.$telephone->number_qs[1];
                                }
                            }

                            $detail->price = $sln->price*$sln->qty;
                            $detail->quantity = $sln->qty;

                            //Ignore if HSI data service code
                            if(!isset($HSI_data[$detail->service_code]))
                            {
                                $this->repository->saveDetail($detail);
                            }
                        }
                    }
                }

                foreach($between_hl->find('ITA#rel_code=A') as $ita)
                {
                    if($ita instanceof ITA)
                    {
                        $detail = new Detail();
                        $detail->code = '1004';
                        $detail->invoice = $invoice->invoice;
                        $detail->number = $hl_holder["number"];
                        $detail->feature = $this->execute_desiredDesc($ita->description.'[end]',$detail->service_code);
                        $detail->price = $ita->amount/100;
                        $detail->quantity = 1;
                        $this->repository->saveDetail($detail);
                    }
                }

                //HSI Data by looking for the 1st of the HSI pair
                foreach($between_hl->find('
                SI#services[0]=CLSLT, SI#services[0]=LDLTL, SI#services[0]=CLSMX, SI#services[0]=LLSMX,
                SI#services[0]=HLSMB, SI#services[0]=LLDUL, SI#services[0]=LSMXC, SI#services[0]=UL6SV,
                SI#services[0]=FL479, SI#services[0]=FL632, SI#services[0]=DULLC, SI#services[0]=CLDLT,
                SI#services[0]=HBL01, SI#services[0]=HLDMB, SI#services[0]=BDISM, SI#services[0]=BDIDM,
                SI#services[0]=FL634, SI#services[0]=BSICV, SI#services[0]=H3BP3, SI#services[0]=LSULC,
                SI#services[0]=CLDMX, SI#services[0]=LDULC, SI#services[0]=DL531, SI#services[0]=LSMXL,
                SI#services[0]=LDMXC, SI#services[0]=SLS75, SI#services[0]=HL405, SI#services[0]=FL668,
                SI#services[0]=SULLC,
                SI#services[0]=CLSUL,
                SI#services[0]=DL655,
                SI#services[0]=LPDLT,
                SI#services[0]=CDLV6,
                SI#services[0]=BDL8D,
                SI#services[0]=LPSLT,
                SI#services[0]=FL622,
                SI#services[0]=HLDHB,
                ') as $hsi_si)
                {

                    //HSI Data by looking for the 2nd of the HSI pair
                    if($hsi_si instanceof SI)
                    {
                        foreach($between_hl->find('
                            SI#services[0]=CPSLT, SI#services[0]=5PDLT, SI#services[0]=CPSMX, SI#services[0]=LPSMX,
                            SI#services[0]=H3MB, SI#services[0]=H5MB, SI#services[0]=SPSMX, SI#services[0]=LPSMX, SI#services[0]=UP6SV,
                            SI#services[0]=FP479, SI#services[0]=FP632, SI#services[0]=PDULC, SI#services[0]=CPDLT, SI#services[0]=HBP01,
                            SI#services[0]=BDLSM, SI#services[0]=BDLDM, SI#services[0]=FP634, SI#services[0]=BSLCV, SI#services[0]=H3L3B,
                            SI#services[0]=SPSUL, SI#services[0]=CPDMX, SI#services[0]=SPDUL, SI#services[0]=DP531, SI#services[0]=5PSMX,
                            SI#services[0]=SPDMX, SI#services[0]=SPS75, SI#services[0]=HP405, SI#services[0]=FP668, SI#services[0]=PSULC,
                            SI#services[0]=LPDUL,
                            SI#services[0]=CPSUL,
                            SI#services[0]=DP655,
                            SI#services[0]=LLDLT,
                            SI#services[0]=CDIV6,
                            SI#services[0]=BDI8D,
                            SI#services[0]=LLSLT,
                            SI#services[0]=FP622,
                            SI#services[0]=H7MB,
                        ') as $SecondHSIPair)
                        {
                            if($SecondHSIPair instanceof SI)
                            {
                                $detail = new Detail();
                                $detail->code = '1002';
                                $detail->invoice = $invoice->invoice;
                                $detail->number = $hl_holder["number"];
                                $SLN_current = $hsi_si->prev('SLN');
                                $SLN_next = $SecondHSIPair->prev('SLN');
                                if($SLN_current instanceof SLN && $SLN_next instanceof SLN)
                                {
                                    $detail->price = $SLN_next->price + $SLN_current->price;
                                }
                                $detail->feature .= ' '.$HSI_data[$hsi_si->services[0]];
                                $detail->service_code = $hsi_si->services[0];
                                $this->repository->saveDetail($detail);
                            }
                        }
                    }
                }
                break;
            case 9:
                foreach($between_hl->find('TCD') as $tcd)
                {
                    if($tcd instanceof TCD)
                    {
                        $detail = new Detail;
                        $detail->code = '1003';
                        $detail->invoice = $invoice->invoice;
                        $detail->quantity = 1;
                        $detail->number = $hl_holder["number"];
                        $detail->price = $tcd->amount1;
                        $detail->feature = $this->process_tcd_feature($tcd);
                        $detail->usage = ($tcd->value1*60)+$tcd->value2;
                        $detail->start = $this->process_tcd_date_time($tcd);
                        $serviceCharId = $tcd->next();
                        if ($serviceCharId instanceof SI)
                        {
                            $detail->data = isset($serviceCharId->services[2]) ? str_replace('-', '', $serviceCharId->services[2]) : '';
                            $detail->service_code = isset($serviceCharId->services[5]) ? $serviceCharId->services[5] : '';
                            if($detail->service_code == "LO")
                            {
                                //Directory Assistance
                                $detail->service_code = "Directory Assistance";
                            }
                        }
                        $this->repository->saveDetail($detail);
                    }
                }
                foreach($between_hl->find('USD') as $usd)
                {
                    if($usd instanceof USD)
                    {
                        //the previous hl
                        $hl_8 = $hl_holder['hl_8'];
                        $hl_4 = $hl_holder['hl_4'];

                        /**
                         * @var HL    $hl_8
                         * @var HL    $hl_4
                         */

                        //charge desc
                        $pid = $hl_8->find('PID')->first();
                        //charge qty,price
                        $sln = $hl_8->find('SLN')->first();
                        //price of the usage summary on that branch
                        $it1 = $hl_4->find('IT1#price')->first();
                        /**
                         * @var PID    $pid
                         * @var SLN    $sln
                         * @var IT1    $it1
                         */

                        $summary = array(
                            'calls' => 0,
                            'minutes' => 0,
                            'description' => $pid->desc,
                            'hl'=> $hl->id,
                            'price' => 0
                        );

                        $detail = new Detail;
                        $detail->code = '1002';
                        $detail->invoice = $invoice->invoice;
                        $detail->number = $hl_holder["number"];
                        $detail->feature .= $pid->desc.' ';

                        $si = $usd->next();
                        $detail->feature = 'See '.$pid->desc.' Summary,  '.$usd->qty1;

                        //$detail->feature .= $usd->qty1;

                        if($usd->unit_code == "MJ")
                        {
                            $detail->feature .= ": Minutes.";
                            $summary['minutes'] = $usd->qty1;
                        }
                        elseif($usd->unit_code == "C0")
                        {
                            $detail->feature .= ": Calls.";
                            $summary['calls'] = $usd->qty1;
                        }

                        $summary['price'] = $sln->price +0;
                        //saves an individual USD summary
                        $this->saveSummary($US,$summary);

                        if($si instanceof SI)
                        {
                            //$detail->feature .= " AREA : ".$si->services[1].", ";
                            if($si->services[0] == "03")
                            {
                                $detail->feature .= " (Day) ";
                            }
                            elseif($si->services[0] == "04")
                            {
                                $detail->feature .= " (Evening) ";
                            }
                            elseif($si->services[0] == "05")
                            {
                                $detail->feature .= " (Night/Weekend) ";
                            }
                        }

                        $this->repository->saveDetail($detail);
                    }
                }
                break;
            default:
                break;
        }

        foreach($hl->find('HL#parent='.$hl->id) as $child_hl)
        {
            if($child_hl instanceof HL)
            {
                $this->execute_hl($invoice,$child_hl,$hl_holder,$US);
            }
        }
    }


    protected function execute_desiredDesc($ediDesc,$serviceCode)
    {
        $desiredDesc = "";

        $revised_descrByServiceID = array(
            "B15PL" => "High Speed Internet Enhanced Static",
            "B15PP" => "High Speed Internet Enhanced Static",
            "BBSEL" => "HSI Dynamic Bus",
            "BBSEP" => "HSI Dynamic Bus",
            "CLDEX" => "Broadband Extreme Dynamic Plus",
            "CPDEX" => "Broadband Extreme Dynamic Plus",
            "CTPEX" => "Communications System Unrestricted Exchange Access",
            "CTPIX" => "Communications System Unrestricted Exchange Access",
            "DP518" => "High Speed Inet - 1/384",
            "DL518" => "High Speed Inet - 1/384",
            "H6BL5" => "High Speed Internet Elite - $105.00",
            "H6BP5" => "High Speed Internet Elite - $105.00",
            "HL369" => "High Speed Inet - 3/768",
            "HP369" => "High Speed Inet - 3/768",
            "HL387" => "High Speed Internet - 5/768",
            "HP387" => "High Speed Internet - 5/768",
            "HL838" => "High Speed Internet - 3/768",
            "HP838" => "High Speed Internet - 3/768",
            "IHX73" => "Business Broadband Power - Dynamic IP",
            "IHX74" => "Business Broadband Power - Dynamic IP",
            "LPDMX" => "Broadband Max",
            "LLDMX" => "Broadband Max",
            "VDO04" => "Solutions Bundle",
            "VDI04" => "Solutions Bundle",
            "SOLF2" => "Solutions Bundle",
            "SOLF3" => "Solutions Bundle",
            "VDO03" => "Addl Line Unlimited Bundle",
            "VDI03" => "Addl Line Unlimited Bundle",
            "F6TRP" => "Addl Line Unlimited Bundle",
            "VLC21" => "Frontier Freedom Business - Unl Local, Toll, & LD",
            //"VDO10" => "Frontier Freedom Business - Unl Local, Toll, & LD",
            //"VDI10" => "Frontier Freedom Business - Unl Local, Toll, & LD",
            "VDI10" => "Freedom Business 1 Yr - Unlimited LD, Local, & Regional Toll",
            //"VDI10" => "Freedom Business 1 Yr - Unlimited LD, Local, & Regional Toll",
            "VDO10" => "Freedom Business 1 Yr - Unlimited LD, Local, & Regional Toll",
            "VLC20" => "Freedom Business 1 Yr - Unlimited LD, Local, & Regional Toll",
            "WZB48" => "LD Discount - to be applied to Solutions Bundle",
            "ULMLD" => "Unlimited Local, Regional and LD Calling",
            "FBMSF" => "Frontier Business Metro- Features & LD",
            "PSULL" => "Business Broadband Ultra",
            "SULLL" => "Business Broadband Ultra",
            "SLF75" => "FiOS 75/75 Static",
            "SPF75" => "FiOS 75/75 Static",
            "SLS15" => "FiOS 150/150 Static",
            "SPS15" => "FiOS 150/150 Static",
            "UL2DV" => "Broadband Max Ultra",
            "UP2DV" => "Broadband Max Ultra",
            "ULCF3" => "Unlimited Dialtone CTX Package",
            "ULBPT" => "Unlimited Dialtone CTX Package",
            "ULBPL" => "Unlimited Dialtone CTX Package",
            "CEXFT" => "Additional Expansion Centrex Package w/o LD",
            "RE242" => "Additional Centrex Package Credit",
            "RE317" => "Expansion Centrex Additional Credit",
            "RE289" => "Unlimited Dial Tone Custom Value Discount",
            "FSULR" => "Ftr Simply Unlimited",
            "F2ULR" => "Ftr Simply Unlimited",
            "FL503" => "FiOS 25/25 2Yr - Bus ",
            "FP503" => "FiOS 25/25 2Yr - Bus ",
            "FSCL1" => "Ftr Simply Unlimited",
            "FSCL4" => "Ftr Simply Unlimited",
            "F2CL4" => "Ftr Simply Unlimited",
            "FSCC1" => "Ftr Simply Unlimited",
            "POMP3" => "Business Security Bundle - Premium Tech Support",
            "POMU3" => "Business Security Bundle - Backup & Sharing",
            "POMM3" => "Business Security Bundle - Max Security",
            "VGMA1" => "Voice Grade Mileage - Additional Line Mileage",
        );

        $partialwords = array(
            //1
            " Sls[end]",
            " Excise T[end]",
            " Exc[end]",
            "Svc[end]",
            " Surchrg[end]",

            //2
            " Sur[end]",
            " Srch[end]",
            " Srchg[end]",
            " Lcl Tl Sl[end]",
            " Char ",

            //3
            " Switched Acc? ",
            " Muni Bus ",
            " Util ",
            " Utiluse ",
            " Optl ",

            //4
            " Tncl ",
            " Comm? ",
            " GRS ",
            " Rcv ",
            " Accss ",

            //5
            " TRS ",
            " TelSC? ",
            " LcnsFee ",
            " Additiona[end]",
            " ARC ",

            //6
            "Com Video[end]",
            " Prty ",
            " Protectio ",
            " St ",
            " Chrg ",

            //7
            " Maintenance C[end]",
            " Multi-Ln[end]",
            " Multi-Ln",
            " Sls Tax[end]",
            " Sls T[end]",

            //8
            "Multi-Line Federal Subscr[end]",
            "Franchi[end]",
            "Mlti-Ln ",
            "Disabled Fun[end]",
            "Subscribe[end]",

            //9
            " Fed ",
            "Char[end]",
            "Pre-Subsc[end]",
            " LD ",
            "Feder[end]",

            //10
            " Comm ",
            "GRS[end]",
            " Co ",
            "Line Sharing Additio[end]",
            " Flat Busi[end]",

            //11
            " Svc ",
            " State 911[end]",
            "Federal Subscriber Line C[end]",
            " Screening-I[end]",
            "Acc Rec ",

            //12
            " Srch-",
            " LFLN ",
            " TRS[end]",
            " Nationa[end]",
            " Chrg-",

            //13
            "Busi[end]",
            "Forwarding -[end]",
            "Dl ",
            " Prty ",
            " Rly ",

            //14
            "Work Recove[end]",
            " Cnty ",
            " Tlcm ",
            " Primary Carrier S[end]",
            " Listing No[end]",

            //15
            " Util[end]",
            "Centrex Packag[end]",
            " Centrex Additio[end]",
            "ARC ",
            " Sl[end]",

            //16
            " Carrier C[end]",
            " Utility[end]",
            "OneVoice Price Protection[end]",
            "OneVoice Features[end][end]",
            "Business Line - Select Lo[end]",

            //17
            "OneVoice Long Distance[end]",
            "OneVoice Features[end]",
            " Line C[end]",
            "Ults Surcharge[end]",
            "PUC ",

            //18
            " Adv Serv CASF",
            " Hcf-A ",
            " Surchg[end]",
            " -[end]",
            " Utility Receipt[end]",

            //19
            "Protectio[end]",
            " Intra Rstrt ",
            " Extra L[end]",
            "Call Waiting/Cancel Call[end]",
            " Acc Surc[end]",

            //20
            " Call Fo[end]",
            " EAS[end]",
            "Non Published Listing No Charge",
            "ISDN BRI SLB Multi Access[end]",
            "ISDN BRI SLB Circuit Swit[end]",

            //21
            " International C[end]",
            " LcnsFee[end]",
            " TelSC[end]",
            " Schrg[end]",
            " Infrstr Mte ",

            //22
            " Carrier M[end]",
            " Charg[end]",
            "  + Basic Cal[end]",
            " Voice Ma[end]",
            " Cty ",

            //23
            " Termina[end]",
            " Muni Bus ",
            " Lcl ",
            " Tl ",
            " Muni Bus[end]",

            //24
            " Lcl[end]",
            " Tx[end]",
            " Rout[end]",
            " Simple 7 Toll Fr[end]",
            "Fed Reg Fee Bus[end]",

            //25
            "9-1-1 State Tax[end]",
            "Business High Speed Inter"
        );
        $partialwordsComplete = array(
            //1
            " Sales Tax",
            " Excise Tax",
            " Excise",
            " Service ",
            " Surcharge ",

            //2
            " Surcharge ",
            " Surcharge ",
            " Surcharge ",
            " Local Tel Sales Tax ",
            " Charge ",

            //3
            " Switched Access Rate Recovery ",
            " Municipal Right of Way Surcharge ",
            " Utility ",
            " Utility Use ",
            " Operational ",

            //4
            " Technical ",
            " Communications ",
            " Gross Receipts Tax ",
            " Recovery ",
            " Access ",

            //5
            " Telecom Relay Service ",
            " Tel Sales Tax ",
            " License Fee ",
            " Additional ",
            " Access Recovery Charge ",

            //6
            " Video Communications Service Tax ",
            " Party ",
            " Protection ",
            " State ",
            " Charge ",

            //7
            " Maintenance Credit ",
            " Multi-line",
            " Multi-line",
            " Sales Tax",
            " Sales Tax",

            //8
            "Multi-Line Federal Subscriber Line Charge",
            "Franchise Fee",
            "Multi-Line ",
            "Disabled Funding Fee",
            "Subscriber Line Charge",

            //9
            " Federal ",
            "Charge",
            "Pre-Subscribed Line Charge",
            " Long distance ",
            "Federal Line Charge",

            //10
            " Communications ",
            " Gross Receipts Tax ",
            " County ",
            "Line Sharing Additional Device",
            " Flat Business Line ",

            //11
            " Service ",
            " State 911 Surcharge ",
            "Federal Subscriber Line Charge",
            " Screening-Inbound",
            "Access Recovery ",

            //12
            " Surcharge-",
            " life-line ",
            " Telcom Relay Service ",
            " National ",
            " Charge-",

            //13
            "Business",
            "Forwarding - line",
            "Dual ",
            " Party ",
            " Relay ",

            //14
            "Work Recovery Surcharge",
            " County ",
            " Telecom ",
            " Primary Carrier Single Line Charge",
            " Listing No Charge",

            //15
            " Utility Tax",
            " Centrex Package",
            " Centrex Addition",
            "Access Recovery Charge ",
            " Sales Tax",

            //16
            " Carrier Charge",
            " Utility Tax",
            "OneVoice Nationwide",
            "OneVoice Nationwide",
            "Business Line - Select Local Calling",

            //17
            "OneVoice Nationwide",
            "OneVoice Nationwide",
            " Line Charge",
            "Universal Lifeline Telephone Service Surcharge",
            "Public Utilities Commission ",

            //18
            " CASF - High Cost Fund ",
            " State High Cost Fund -A ",
            " Surcharge",
            " -Bus",
            " Utility Receipt Surcharge",

            //19
            "Protectio[end]",
            " Intra Restructure ",
            " Extra Lines",
            "Call Waiting/Cancel Call waiting",
            " Access Recovery Surcharge",

            //20
            " Call Forwarding feature",
            " EAS Recovery Surcharge",
            "Non Published Listing Discount",
            "ISDN BRI SLB Multi Access Service",
            "ISDN BRI SLB Circuit Switched Voice",

            //21
            " International Calling plan",
            " License Fee",
            " Tel Sales Tax",
            " Surcharge",
            " Infrastructure Maintenance ",

            //22
            " Carrier Multi-Line Charge ",
            " Charge",
            "  + Basic Calling",
            " Voicemail",
            " City ",

            //23
            " Terminal",
            " Municipal Right of Way Surcharge ",
            " Local ",
            " Tel ",
            " Municipal Right of Way Surcharge",

            //24
            " Local Sales tax",
            " Tax",
            " Router",
            " Simple 7 Toll Free Monthly Charge",
            "Federal Regulatory Fee",

            //25
            "9-1-1 State Surcharge",
            "Business High Speed Internet Fee"

        );
        if(isset($revised_descrByServiceID[$serviceCode]))
        {
            return trim($revised_descrByServiceID[$serviceCode]);
        }
        $desiredDesc = trim(str_replace($partialwords,$partialwordsComplete,$ediDesc));
        $desiredDesc = str_replace("[end]"," ",$desiredDesc);
        $desiredDesc = str_replace("Communications  Service","Communications  Service Tax",$desiredDesc);

        if($desiredDesc != "")
        {
            return trim($desiredDesc);
        }
        else
        {
            return $ediDesc;
        }
    }


    /********************************************************
    saveSummary(&$US, $summary) stores the usage summaries
    /********************************************************/

    protected function saveSummary(&$US, $summary)
    {
        if(sizeof($US) == 0)//empty
        {
            array_push($US,$summary);
        }
        else
        {
            $counter = sizeof($US);
            $foundsameDesc = false;
            while($counter >0)
            {
                $counter--;
                if($summary['description'] == $US[$counter]['description'])
                {
                    $calls = $summary['calls'] + $US[$counter]['calls'];
                    $minutes = $summary['minutes'] + $US[$counter]['minutes'];

                    $US[$counter]['calls'] = $calls;
                    $US[$counter]['minutes'] = $minutes;

                    //a new hl
                    if($summary['hl'] != $US[$counter]['hl'])
                    {
                        $US[$counter]['hl'] = $summary['hl'];
                        $US[$counter]['price'] = $summary['price']+$US[$counter]['price'];
                    }
                    $foundsameDesc = true;
                    break;
                }
            }
            if($foundsameDesc == false)
            {
                array_push($US,$summary);
            }
        }
    }

    protected function read_account(BIG $transaction, Invoice $invoice){
        $ap = $transaction->find('REF#ref_id_q=11')->first();
        if($ap instanceof REF){
            $invoice->account = ltrim($ap->ref_id, '0');
        } else {
            $ma = $transaction->find('REF#ref_id_q=14')->first();
            if($ma instanceof REF){
                $invoice->account = ltrim($ma->ref_id, '0');
                $check_alias = Alias::find('account', '=', $invoice->account)
                    ->and('provider', 'IN', array('0', $this->provider->id()))
                    ->get();
                if($check_alias instanceof Alias && $check_alias->id()){
                    $invoice->account_alias = $check_alias->alias;
                }
            }
            $sa = $transaction->find('REF#ref_id_q=11')->first();
        }
    }

    protected function process_addresses(BIG $transaction, Invoice $invoice){
        /**
         * @var \X12\V4010\C811\Segment\N1    $payer
         */
        /**
         * @var \X12\V4010\C811\Segment\N3 $payer_address_el
         */
        /**
         * @var \X12\V4010\C811\Segment\N4 $payer_address2
         */
        $payer_info = "";
        $payer_address = "";
        $payer = $transaction->find('N1#entity_code=PR')->first();
        if($payer){
            $payer_info = $payer->name;
            $payer_address_el = $payer->next();
            if($payer_address_el instanceof N3){
                if($payer_address_el->address == "DBA COLONIAL PLACE")
                {
                    $payer_address = "PO BOX 1607 ";
                }
                else
                {
                    $payer_address = $payer_address_el->address . ($payer_address_el->address2 ? ' ' . $payer_address_el->address2 : '') . "\r\n";
                }
                $payer_address2 = $payer_address_el->next();

                if($payer_address2 instanceof N4){
                    $payer_address .= $payer_address2->city . ', ' .  $payer_address2->state . ' ' . substr($payer_address2->postal, 0, 5);
                }
            }
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
        return array($payer_info, $payer_address, $payee_name, $payee_info, $loc_info);
    }

    protected function read_header(BIG $transaction){
        $invoice = new Invoice;
        $invoice->batch = $this->batch;
        $invoice->sender = $this->sender;
        $invoice->date_received = 'now';
        $invoice->date_imported = 'now';
        $invoice->attempts++;

        $dtm = $transaction->find('DTM#date_q=150')->first();
        if($dtm instanceof DTM){
            $invoice->period_start = substr($dtm->date, 4, 2) . '/' . substr($dtm->date, 6, 2) . '/' . substr($dtm->date, 0, 4);
        }

        $dtm = $transaction->find('DTM#date_q=151')->first();
        if($dtm instanceof DTM){
            $invoice->period_end = substr($dtm->date, 4, 2) . '/' . substr($dtm->date, 6, 2) . '/' . substr($dtm->date, 0, 4);
        }

        $tds = $transaction->find('TDS')->first();
        if($tds instanceof TDS){
            $invoice->trans_value = number_format($tds->amount/100, 2, '.', '');
        }

        $this->process_billed_adjustment($transaction, $invoice);

        $previous_billed_exists = false;
        $bal = $transaction->find('BAL#code=P#amount_q=PB')->first();
        if($bal instanceof BAL){
            $previous_billed_exists = true;
            $invoice->previous_billed = floatval($bal->amount);
        }

        $previous_paid_exists = false;
        $bal = $transaction->find('BAL#code=M#amount_q=TP')->first();
        if($bal instanceof BAL){
            $previous_paid_exists = true;
            $invoice->previous_paid = floatval($bal->amount);
        }



        $bal = $transaction->find('BAL#code=M#amount_q=J9')->first();//provider's balance forward
        if($previous_billed_exists && $previous_paid_exists){
            $invoice->balance_forward = round($invoice->previous_billed, 2) + round($invoice->previous_paid, 2);
        } //Invoice BF is calculated
        elseif($bal instanceof BAL)
        {
            $invoice->balance_forward = floatval($bal->amount);
        }

//        if(floatval($bal->amount) != 0) { //if the provider's BF is not 0.00, then...
//            if ($invoice->balance_forward != floatval($bal->amount)) { //if the calculated BF does not match the provider's BF, then...
//                $invoice->balance_forward = floatval($bal->amount); //use the provider's BF
//            }
//        }

        $this->process_amount_due($transaction, $invoice);

        $this->process_payments($transaction, $invoice);

        $this->process_current_charges($invoice);

//        $bal = $transaction->find('BAL#code=M#amount_q=PB')->first();
        $bal2 = $transaction->find('BAL#code=A#amount_q=NA')->first();
        if($bal2 instanceof BAL) {
            $invoice->billed_adjustment = $bal2->amount;
        }
//        if($bal instanceof BAL) {
//            $invoice->current_charges = $bal->amount;
//        }
        list($payer_info, $payer_address, $payee_name, $payee_info, $loc_info) = $this->process_addresses($transaction, $invoice);

        $invoice->payer = $payer_info;
        $invoice->payer_address = $payer_address;
        $invoice->provider = $payee_name;
        $per = $transaction->find("PER#contact_code=BI")->first();
        if($per instanceof PER){
            $invoice->provider_phone = isset($per->number_qs[1]) ? $per->number_qs[1] : '';
            $pin = $transaction->find('REF#ref_id_q=CR');
            $invoice->provider_phone .= ' PIN: '.substr($pin,7, 4);
        }
        $invoice->payee = $payee_info;
        $invoice->location = $loc_info;
        $invoice->invoice = $transaction->invoice;
        $this->read_account($transaction, $invoice);
        $invoice->date = date("Y-m-d", strtotime($transaction->date));
        /**
         * @var \X12\V4010\C811\Segment\ITD    $date
         */
        $date = $transaction->find('ITD')->first();
        $invoice->date_due = $date instanceof ITD ? date("Y-m-d", strtotime($date->date_due)) : '0000-00-00';

        $invoice->status = 'pending';
        $this->repository->saveInvoice($invoice);

        return $invoice;
    }
}