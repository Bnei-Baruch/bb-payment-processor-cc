<?php

use Civi\Api4\Contribution;
use CRM\BBPelecard\API\Pelecard;
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

    function validateResult(&$paymentProcessor, &$input, &$contribution): array {
        if ($input['UserKey'] != $input['qfKey']) {
            Civi::log('BBPCC IPN')->debug("Pelecard Response param UserKey is invalid");
            return [false, null];
        }

        $input['amount'] = $contribution['total_amount'];
        list($valid, $data, $errorCode) = $this->_bbpAPI->validateResponse($paymentProcessor, $input, $contribution);

        if (!$valid) {
            Civi::log('BBPCC IPN')->debug("Pelecard Response is invalid");

            if ($errorCode > 0) {
                Contribution::update(false)
                    ->addWhere('id', '=', $contribution['id'])
                    ->addValue('invoice_number', (string)$errorCode)
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
