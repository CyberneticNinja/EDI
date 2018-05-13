<?php
    namespace OneSource\EDI\Provider\Windstream;

    use OneSource\EDI\Import\ImporterAbstract,
        OneSource\EDI\Model\Detail,
        OneSource\EDI\Model\Line,
        OneSource\EDI\Model\Invoice,
        OneSource\EDI\Logger\Logger;

    use OneSource\EDI\Model\Alias;
    use OneSource\EDI\Model\InvoiceCharge;
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
    use X12\QueryCollection;
    use X12\Segment;
    use X12\V4010\C811\Segment\AMT;
    use X12\V4010\C811\Segment\HL;
    use X12\V4010\C811\Segment\IT1;
    use X12\V4010\C811\Segment\ITD;
    use X12\V4010\C811\Segment\BIG;
    use X12\V4010\C811\Segment\REF,
        X12\Exception;

    class Importer extends ImporterAbstract {
        protected static $zero_charges = array(
            'ATR10',
            'ATR12',
            'ATR56',
            'ATR7',
            'ATR8',
            'BFEA1',
            'BFEA2',
            'BFEA6',
            'BISP5',
            'BISP6',
            'BMERA',
            'BZACL',
            'BZBB1',
            'BZIP2',
            'BZIP6',
            'BZIP7',
            'BZIPA',
            'BZLC2',
            'BZLC7',
            'BZMCF',
            'BZOLD',
            'BZRIT',
            'BZULD',
            'CALB2',
            'CALB3',
            'CULDA',
            'FLXN',
            'SBFLX',
            'SBULD',
            'ULDAT',
            'ULDB',
            'W3LES',
            'W6LES',
            //discouts
            'ATR54',
            'ATR9',
            'BLBLA',
            'BLEBL',
            'BMMS0',
            'BUNC2',
            'BZCR1',
            'BZCR5',
            'BZIP6',
            'CALC1',
            'CALC2',
            'FCH8B',
            'FEC21',
            'FEC61',
            'FECH7',
            'FECH8',
            'FLXNC',
            'BLEC3',
            'BNCR3',
            'ACLB1',
            'BNCR2',
            'BBI3',
            'FCH3B',
            'BBI6',
            'FECH4',
            'BLEC6',
            'BPBRV',
            'BPRV3',
            'OBBRV',
            'PCBRV',
            'SBFLX',
            'SSBRV',
            'THBRV',
            'ULDBB',
        );
        protected function read_account(BIG $transaction, Invoice $invoice){
            $ap = $transaction->find('REF#ref_id_q=AP')->first();
            if($ap instanceof REF){
                $invoice->account = $ap->ref_id;
            } else {
                $ma = $transaction->find('REF#ref_id_q=14')->first();
                if($ma instanceof REF){
                    $invoice->account = $ma->ref_id;
                }
                $sa = $transaction->find('REF#ref_id_q=11')->first();
                if($sa instanceof REF){
                    $invoice->subaccount = $sa->ref_id;
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
            }
            $check_alias = Alias::find('account', '=', $invoice->account)
                ->and('provider', 'IN', array('0', $this->provider->id()))
                ->get();
            if($check_alias instanceof Alias && $check_alias->id()){
                $invoice->account_alias = $check_alias->alias;
            } else {
                $check_invoice = Invoice::find('account', '=', $invoice->account)
                    ->and_where('account_alias', '!=', '')
                    ->order_by('date', 'desc')
                    ->get();
                if($check_invoice instanceof Invoice && $check_invoice->id()){
                    $invoice->account_alias = $check_invoice->account_alias;
                }
            }
        }

        protected function process_billed_adjustment(BIG $transaction, Invoice $invoice){
            $bal = $transaction->find('BAL#code=A#amount_q=NA')->first();
            if($bal instanceof BAL) {
                $invoice->billed_adjustment = floatval($bal->amount);
            }
        }

        protected function process_current_charges(Invoice $invoice){
            $invoice->current_charges = floatval($invoice->trans_value);
        }

        public function process(array $options = array()){
            $this->triggerEvent('init');
            $parser = new Parser($this->input, 'V4010', 'C811');
            try {
                $doc = $parser->process();
            } catch(Exception $e){
                throw $e;
            }
            $this->data['document'] = $doc;
            $transactions = $doc->find('BIG');
            $this->data['total'] = count($transactions);
            $this->data['count'] = 0;
            $this->triggerEvent('prepare');
            foreach($transactions as $transaction){
                /**
                 * @var \X12\V4010\C811\Segment\BIG $transaction
                 */
                $this->data['count']++;
                if(Invoice::count('invoice', '=', $transaction->invoice)->get() || $this->triggerEvent('start', $transaction) === false){
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

                $lines = array();
                $main_line = '0000000000';

                $hl1 = $transaction->between('HL#level=1', 'HL');

                $si = $hl1->find('SI#service_qs[0]=BN')->first();
                if($si instanceof SI){
                    $main_line = isset($si->services[0]) ? $si->services[0] : '0000000000';
                    if($main_line != '0000000000'){
                        $lines[] = $main_line;
                    }
                }

                $taxes = $transaction->find('TXI');
                foreach($taxes as $tax){
                    /**
                     * @var \X12\V4010\C811\Segment\TXI    $tax
                     */
                    $dt = new Detail;
                    $dt->code = '1004';
                    $dt->invoice = $invoice->invoice;
                    $dt->number = $tax->id ? $tax->id : $main_line;
                    $dt->price = $tax->amount;
                    $dt->quantity = 1;
                    $dt->feature = $this->parse_tax_code($tax->type_code);
                    $this->repository->saveDetail($dt);
                }

                $charges = $transaction->find('ITA#indicator=A,ITA#indicator=C');
                foreach($charges as $charge){
                    /**
                     * @var \X12\V4010\C811\Segment\ITA    $charge
                     */
                    $dt = new Detail;
                    $dt->code = '1005';
                    $dt->invoice = $invoice->invoice;
                    $dt->number = $main_line;
                    $dt->price = number_format($charge->amount/100, 2, '.', '');
                    if($charge->charge_code == 'LPC'){
                        $invoice->current_charges += floatval($dt->price);
                        $dt->feature = 'Late Payment Charge';
                        $invoice->billed_adjustment += floatval($dt->price);
                    } else {
                        $dt->feature = $charge->description;
                    }
                    if($dt->feature && $dt->price != 0){
                        $this->repository->saveDetail($dt);
                    }
                }

                foreach($transaction->find('HL#level=4#child=0') as $hl4){
                    /**
                     * @var HL  $hl4
                     */
                    foreach($hl4->between($hl4, 'HL')->find('IT1') as $it1){
                        /**
                         * @var IT1 $it1
                         */
                        $dt = new Detail;
                        $dt->code = '1002';
                        $dt->invoice = $invoice->invoice;
                        $dt->price = number_format($it1->price, 2, '.', '');
                        $dt->quantity = $it1->qty;
                        $dt->feature = 'Unknown';
                        $el = $it1;
                        $break = false;
                        while(($el = $el->next()) && !$break){
                            if($el instanceof SI){
                                if(isset($el->service_qs[0]) && $el->service_qs[0] == 'BN'){
                                    $dt->number = isset($el->services[0]) ? $el->services[0] : $main_line;
                                    if($dt->number && !in_array($dt->number, $lines)){
                                        $lines[] = $dt->number;
                                    }
                                } else if(isset($el->service_qs[0]) && $el->service_qs[0] == 'SC'){
                                    $dt->service_code = isset($el->services[0]) ? $el->services[0] : '';
                                }
                            } else if($el instanceof PID){
                                $dt->feature = $el->desc ? $el->desc : $dt->feature;
                                if(preg_match('/(FEE)|(SURCHRG)|(SURCHARGE)|(CHARGE)|(CHG)/i', $dt->feature)){
                                    $dt->code = '1005';
                                }
                            } else {
                                $break = true;
                            }
                        }
                        if($dt->feature != 'Unknown' || $dt->price != 0){
                            $this->repository->saveDetail($dt);
                        }
                    }
                }

                foreach($transaction->find('HL#level=4#child=1') as $hl4){
                    /**
                     * @var HL  $hl4
                     */
                    $child = $transaction->find('HL#level=8#parent=' . $hl4->id)->first();
                    if(!($child instanceof HL)){
                        continue;
                    }
                    foreach($child->between($child, 'HL')->find('SLN#rel_code2=I') as $sln){
                        /**
                         * @var SLN $sln
                         */
                        $dt = new Detail;
                        $dt->code = '1002';
                        $dt->invoice = $invoice->invoice;
                        $dt->price = number_format($sln->price, 2, '.', '');
                        $dt->quantity = $sln->qty;
                        $dt->feature = 'Unknown';
                        $dt->service_code = $sln->id1;
                        $el = $sln;
                        $break = false;
                        while(($el = $el->next()) && !$break){
                            if($el instanceof SI){
                                if(isset($el->service_qs[0]) && $el->service_qs[0] == 'BN'){
                                    $dt->number = isset($el->services[0]) ? $el->services[0] : $main_line;
                                    if($dt->number && !in_array($dt->number, $lines)){
                                        $lines[] = $dt->number;
                                    }
                                } else if(isset($el->service_qs[2]) && $el->service_qs[2] == 'BI'){
                                    $dt->code = '1005';
                                    $dt->number = isset($el->services[2]) ? $el->services[2] : $main_line;
                                    if($dt->number && !in_array($dt->number, $lines)){
                                        $lines[] = $dt->number;
                                    }
                                }
                            } else if($el instanceof PID){
                                $dt->feature = $el->desc ? $el->desc : $dt->feature;
                            } else {
                                $break = true;
                            }
                        }
                        if($dt->feature != 'Unknown' && $dt->price != 0){
                            $this->repository->saveDetail($dt);
                        }
                    }
                }

                $usages = $transaction->find('TCD');
                foreach($usages as $usage){
                    /**
                     * @var \X12\V4010\C811\Segment\TCD    $usage
                     */
                    $si = $usage->next();
                    if($si instanceof SI){
                        $dt = new Detail;
                        $dt->code = '1003';
                        $dt->class = isset($si->services[7]) ? $si->services[7] : '';
                        $dt->invoice = $invoice->invoice;
                        $dt->number = isset($si->services[1]) ? $si->services[1] : '';
                        $dt->data = isset($si->services[0]) ? $si->services[0] : '';
                        if($dt->number && !in_array($dt->number, $lines)){
                            $lines[] = $dt->number;
                        }
                        $dt->quantity = 1;
                        $dt->price = $usage->rel_code == 'O' ? 0 : round($usage->amount2, 2);
                        $dt->feature = $usage->location2 . ', ' . $usage->state2 . ' => ' . $usage->location1 . ', ' . $usage->state1 . ($usage->rel_code == 'O' ? ' ($' . number_format($usage->amount2, 2) . ')' : '');
                        $dt->service_code = isset($si->services[7]) ? $si->services[7] : 'IA';
                        if(!in_array($usage->state1, static::$states)){
                            $dt->service_code = 'ZZ';
                        }
                        $dt->usage = $usage->value2;
                        $dt->start = $this->process_tcd_date_time($usage);
                        $this->repository->saveDetail($dt);
                        $nm1 = $usage->prev();
                        if($nm1 instanceof NM1 && !preg_match('/^WIND/i', $nm1->name)){
                            $invoice_charge = new InvoiceCharge;
                            $invoice_charge->invoice = $invoice->invoice;
                            $invoice_charge->charge = $nm1->name;
                            $invoice_charge->amount = $dt->price;
                            $invoice_charge->detail = $dt->id();
                            $this->repository->saveCharge($invoice_charge);
                        }
                    }
                }

                foreach($lines as $line){
                    if(!Line::count('account', '=', $invoice->account)->and_where('number', '=', $line)->get()){
                        $ln = new Line;
                        $ln->account = $invoice->account;
                        $ln->number = $line;
                        $this->repository->saveLine($ln);
                    }
                }

                $this->repository->saveInvoice($invoice);

                $this->triggerEvent('finish');
            }
            $this->triggerEvent('final');
        }
    }