<?php

/**
 * Created by PhpStorm.
 * User: sabrokwah
 * Date: 3/22/2017
 * Time: 9:27 AM
 */

namespace OneSource\EDI\Provider\AccessPoint;

use OneSource\EDI\Import\ImporterAbstract;
use OneSource\EDI\Logger\Logger;
use OneSource\EDI\Model\Detail;
use OneSource\EDI\Model\Invoice;
use OneSource\EDI\Model\InvoicePayment;
use OneSource\EDI\Model\Line;
use OneSource\EDI\Model\Metadata;

class Importer extends ImporterAbstract
{
    const PARENT_ACCOUNT = 0;
    const PARENT_NAME = 1;
    const ACCOUNT = 2;
    const NAME = 3;
    const BTN = 4;
    const CORP_ACT = 5;
    const IR = 6;
    const INVOICE = 7;
    const INVOICE_DATE = 8;
    const DUE_DATE = 9;
    const DETAIL_ID = 10;
    const DETAIL_TYPE = 11;
    const SUBDETAIL_ID = 12;
    const SUBDETAIL_TYPE = 13;
    const USGSVC_ID = 14;
    const SERVICE_NUMBER = 15;
    const CHARGE_START_DATE = 16;
    const CHARGE_END_DATE = 17;
    const QUANTITY = 18;
    const AMOUNT = 19;

    private function process_lines(Invoice $invoice,array $lines = array())
    {
//        var_dump($lines);
        foreach($lines as $line)
        {
            $number = $line;
            $line = Line::find('number', '=', $number)->get();
            if(!$line)
            {
                $line_ = new Line;
                $line_->account = $invoice->account;
                $line_->number = $number;
                $this->repository->saveLine($line_);
            }
        }
    }

    public function process(array $options = array())
    {
        $this->triggerEvent('init');
        $this->data['total'] = 0;
        $this->data['count'] = 0;
        $this->triggerEvent('prepare');

        $invoices = array();

        //lines
        $lines = array();

        if(($handle = fopen($this->input, "r")) !== false) {
            while (($data = fgetcsv($handle, 1024, ",")) !== false) {
                $invoice = $data[static::INVOICE].date("ymd", strtotime($data[static::INVOICE_DATE]));
                if (!in_array($invoice, $invoices) && preg_match('/^(\d)+$/',$invoice)) {
                    //$invoices[] = $invoice;
                    $invoices[] = array(
                        "PARENT_NAME" => $data[static::PARENT_NAME],
                        "ACCOUNT" => $data[static::ACCOUNT],
                        "NAME" => $data[static::NAME],
                        "BTN" => $data[static::BTN],
                        "CORP_ACT" => $data[static::CORP_ACT],
                        "IR" => $data[static::IR],
                        "INVOICE" => $data[static::INVOICE],
                        "INVOICE_DATE" => $data[static::INVOICE_DATE],
                        "DUE_DATE" => $data[static::DUE_DATE],
                        "DETAIL_ID" => $data[static::DETAIL_ID],
                        "DETAIL_TYPE" => $data[static::DETAIL_TYPE],
                        "SUBDETAIL_ID" => $data[static::SUBDETAIL_ID],
                        "SUBDETAIL_TYPE" => $data[static::SUBDETAIL_TYPE],
                        "USGSVC_ID" => $data[static::USGSVC_ID],
                        "SERVICE_NUMBER" => $data[static::SERVICE_NUMBER],
                        "CHARGE_START_DATE" => $data[static::CHARGE_START_DATE],
                        "CHARGE_END_DATE" => $data[static::CHARGE_END_DATE],
                        "QUATITY" => $data[static::QUANTITY],
                        "AMOUNT" => $data[static::AMOUNT],
                    );
                }
            }
        }

        //sort
        array_multisort($invoices);

        $theInvoice = null;
        $listOfInvoices = array();

        $detail = null;
        $details = array();

        //old and current invoice
        $current_invoice = null;
        foreach($invoices as $invoice)
        {
            //if there is a new invoice
            if($invoice['INVOICE'] != $current_invoice)
            {
                $current_invoice = $invoice['INVOICE'];
                $theInvoice = new Invoice();
                $theInvoice->date = date("Y-m-d", strtotime($invoice['INVOICE_DATE']));
                $theInvoice->date_due = date("Y-m-d", strtotime($invoice['DUE_DATE']));
                $theInvoice->date_imported = 'now';
                $theInvoice->date_received = 'now';
                $theInvoice->attempts++;
                $theInvoice->payer = $invoice['NAME'];
                $theInvoice->invoice = $invoice['INVOICE'].date("ymd", strtotime($invoice['INVOICE_DATE']));
                $theInvoice->status = 'pending';
                $theInvoice->account = $invoice['ACCOUNT'];
                $theInvoice->batch = $this->batch;
                $theInvoice->sender = $this->sender;
                $theInvoice->previous_paid = 0;

                if(Invoice::find('invoice', '=', $theInvoice->invoice)->get() instanceof Invoice){
                    $this->logger->log_import(
                        Logger::IMPORT_INVOICE_SKIP,
                        "Skipping import of #" . $theInvoice->invoice,
                        $theInvoice->invoice
                    );
                    //invoice already in database, skip it
                    $theInvoice = null;
                }
            }
            if($theInvoice != null)
            {
                $detail = new Detail;

                $detail->invoice = $invoice['INVOICE'].date("ymd", strtotime($invoice['INVOICE_DATE']));
                $detail->code = '1002';
                $detail->price = floatval($invoice['AMOUNT']);
                $detail->number = $invoice['SERVICE_NUMBER'];
                if($detail->number == "")
                {$detail->number = $invoice['ACCOUNT'];}
                $detail->feature = $invoice['DETAIL_TYPE'];
                if($invoice['SUBDETAIL_TYPE'] != '')
                {
                    $detail->feature = $invoice['SUBDETAIL_TYPE'];
                }

                $detail->service_code = $invoice['DETAIL_TYPE'];

                switch($invoice['DETAIL_TYPE'])
                {
                    case 'Previous Invoice Amount':
                        if($theInvoice instanceof Invoice)
                        {
                            $theInvoice->previous_billed = $invoice['AMOUNT'];
                        }
                        break;
                    case 'Payments Received Thank You':
                        if($theInvoice instanceof Invoice)
                        {
                            $theInvoice->previous_paid = ($invoice['AMOUNT'] + $theInvoice->previous_paid);

                            $p = new InvoicePayment();
                            $p->invoice = $theInvoice->invoice;
                            $p->amount = $invoice['AMOUNT'];
                            $p->save();
                        }
                        break;
                    case 'Total New Charges':
                        if($theInvoice instanceof Invoice)
                        {
                            //$theInvoice->amount_due = $invoice['AMOUNT'];
                            $theInvoice->current_charges = $invoice['AMOUNT'];
                        }
                        break;
                    case 'Total Amount Due':
                        if($theInvoice instanceof Invoice)
                        {
                            $theInvoice->amount_due = $invoice['AMOUNT'];
                            $theInvoice->balance_forward = floatval($theInvoice->previous_billed) + floatval($theInvoice->previous_paid);
                            $detail->invoice = $theInvoice->invoice;
                            $theInvoice->trans_value = $invoice['AMOUNT'];
                            if(trim($invoice['PARENT_NAME']) == 'Martin Marietta Materials' ||
                                trim($invoice['NAME']) == 'Martin Marietta Materials')
                            {
                               $theInvoice->date_due = date("Y-m-d", strtotime($theInvoice->date)+(24*3600*27));
                            }
                            $this->repository->saveInvoice($theInvoice);
                        }
                        break;
                    case 'Call Usage Total':
                        $detail->code = '1002';
                        $this->repository->saveDetail($detail);
                        break;
                    case 'Monthly Recurring Charge':
                        $detail->code = '1002';
                        if($detail->number != $theInvoice->account)
                        {
                            if(!in_array($detail->number,$lines))
                            {
                                $lines[] = $detail->number;
                            }
                        }
                        $detail->feature .= ' ('.substr($invoice['CHARGE_START_DATE'], 4, 2) . '/' . substr($invoice['CHARGE_START_DATE'], 6, 2) . '/' . substr($invoice['CHARGE_START_DATE'], 0, 4);
                        $detail->feature .= ' - '.substr($invoice['CHARGE_END_DATE'], 4, 2) . '/' . substr($invoice['CHARGE_END_DATE'], 6, 2) . '/' . substr($invoice['CHARGE_END_DATE'], 0, 4);
                        $detail->feature .= ')';
                        $this->repository->saveDetail($detail);
                        break;
                    case 'Adjustments':
                        $detail->code = '1002';
                        if($theInvoice instanceof Invoice)
                        {
                            if(isset($theInvoice->billed_adjustment))
                            {
                                $theInvoice->billed_adjustment = $theInvoice->billed_adjustment+$detail->price;
                            }
                            else
                            {
                                $theInvoice->billed_adjustment = $detail->price;
                            }
                            $detail->number = ZERO_NUMBER;
                            $detail->price = 0;
                            $detail->feature .= ' ($'.$invoice['AMOUNT'].')';
                            $this->repository->saveInvoice($theInvoice);
                        }
                        $this->repository->saveDetail($detail);
                        break;
                    case 'Taxes':
                        $detail->code = '1004';
                        $detail->service_code = "Taxes";
                        $this->repository->saveDetail($detail);
                        break;
                    case 'Finance Charges':
                        $detail->code = '1002';
                        $detail->feature = "Finance Charge";
                        $this->repository->saveDetail($detail);
                        break;
                    case 'Non-recurring Charge':
                        $detail->code = '1002';
                        $this->repository->saveDetail($detail);
                        break;
                    default:
                        break;
                }
                $this->process_lines($theInvoice,$lines);
            }
        }
        fclose($handle);
        $this->triggerEvent('final');
    }
}