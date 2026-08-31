<?php

use Civi\Api4\Contribution;
use CRM\BBPelecard\API\Pelecard;
use CRM\BBPelecard\Payment\ActivityUpdater;
use CRM\BBPelecard\Payment\BBPriorityBaseIPN;

class CRM_Core_Payment_BBPriorityCCIPN extends BBPriorityBaseIPN {

    function __construct($inputData) {
        parent::__construct(Pelecard::TYPE_CC, $inputData);
    }

    protected function getTemplateName(): string {
        return 'CRM/Core/Payment/BbpriorityCC.tpl';
    }

    protected function getLogChannel(): string {
        return 'BBPCC IPN';
    }

    protected function linkPendingRegistrationActivities(array $contribution, int $contactID): void {
        ActivityUpdater::updateActivitiesViaPendingActivities((int)$contribution['id'], 'ipn');
    }

    function validateResult(&$paymentProcessor, &$input, &$contribution): array {
        if ($input['UserKey'] != $input['qfKey']) {
            Civi::log('BBPCC IPN')->debug("Pelecard Response param UserKey is invalid");
            return [false, null];
        }

        $input['amount'] = $contribution['total_amount'];
        list($valid, $data, $errorCode) = $this->_bbpAPI->validateResponse($paymentProcessor, $input, $contribution);

        if (!$valid) {
            // The code goes in the log, not in invoice_number.
            //
            // This used to write invoice_number = (string)$errorCode, and it was
            // the last writer of that column once bb2prio and bb2fix stopped
            // writing it on 2026-08-25. Both of those still run on their usual
            // cron schedule — it is the column they stopped using, not the
            // binaries. That column was never an invoice number: the Civi ->
            // Priority reporting pipeline used it as a three-state flag (NULL
            // unsent, '1' sent, '-NNN' error) and now keeps that state in
            // civicrm_bb_report_state instead. The bare codes written here — no
            // '-' prefix — are where the ~12,600 old rows holding '004', '301'
            // and friends come from.
            //
            // Dropping the write loses nothing: contribution_status_id = 4 is
            // what actually keeps these out of every reporting queue, and it is
            // still set below. bb2fix's checkInvoiceNumberStates warns if any
            // writer starts putting unrecognised values in that column again,
            // which is the point of not having one.
            Civi::log('BBPCC IPN')->debug(
                "Pelecard Response is invalid" . ($errorCode > 0 ? " (code {$errorCode})" : "")
            );

            if ($errorCode > 0) {
                Contribution::update(false)
                    ->addWhere('id', '=', $contribution['id'])
                    ->addValue('contribution_status_id', 4)
                    ->execute();
            }
            return [false, null];
        }

        $this->storePaymentResponse($contribution['id'], $data);

        Contribution::update(false)
            ->addWhere('id', '=', $contribution['id'])
            ->addValue('trxn_id', $data['PelecardTransactionId'])
            ->execute();

        return [true, $data];
    }
}
