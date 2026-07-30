<?php

use Civi\Api4\Contribution;
use Civi\Api4\Contact;
use CRM\BBPelecard\Payment\BBPriorityBaseIPN;

class CRM_BbpriorityCC_Page_EmvComplete extends CRM_Core_Page {

    public function run(): void {
        $get = $_GET;

        $success        = $get['success'] ?? '0';
        $token          = $get['token'] ?? '';
        $transactionId  = $get['transaction_id'] ?? '';
        $contributionId = (int)($get['cid'] ?? 0);
        $returnUrl      = $get['rurl'] ?? '';

        if (!$contributionId || $success !== '1' || !$transactionId) {
            \Civi::log('BBPriorityCC')->warning('EmvComplete: invalid callback params', [
                'success'        => $success,
                'transaction_id' => $transactionId,
                'cid'            => $contributionId,
            ]);
            $this->redirectOut($returnUrl, 'error=1');
            return;
        }

        if (BBPriorityBaseIPN::markTransactionProcessed($transactionId, $contributionId, 'BBPriorityCC EMV')) {
            $this->redirectOut($returnUrl);
            return;
        }

        try {
            $contribution = Contribution::get(false)
                ->addWhere('id', '=', $contributionId)
                ->execute()
                ->single();

            $contactId = (int)$contribution['contact_id'];

            Contribution::update(false)
                ->addWhere('id', '=', $contributionId)
                ->addValue('contribution_status_id:name', 'Completed')
                ->addValue('trxn_id', $transactionId)
                ->execute();

            Contribution::update(false)
                ->addWhere('id', '=', $contributionId)
                ->addValue('Payment_details.token',    $token)
                ->addValue('Payment_details.cardtype', $get['credit_card_company_issuer'] ?? '')
                ->addValue('Payment_details.cardnum',  $get['credit_card_number'] ?? '')
                ->addValue('Payment_details.cardexp',  $get['credit_card_exp_date'] ?? '')
                ->execute();

            if ($token && $contactId) {
                Contact::update(false)
                    ->addWhere('id', '=', $contactId)
                    ->addValue('general_token.gtoken', $token)
                    ->execute();
            }

            $this->storePaymentResponse($transactionId, $contributionId, $get);

            \Civi::log('BBPriorityCC')->info('EmvComplete: contribution completed', [
                'contribution_id' => $contributionId,
                'transaction_id'  => $transactionId,
            ]);
        } catch (\Exception $e) {
            \Civi::log('BBPriorityCC')->error('EmvComplete processing error: ' . $e->getMessage());
        }

        $this->redirectOut($returnUrl);
    }

    private function redirectOut(string $returnUrl, string $extraParams = ''): void {
        if (!$returnUrl) {
            \CRM_Utils_System::civiExit();
            return;
        }
        $target = $extraParams
            ? $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . $extraParams
            : $returnUrl;
        \CRM_Utils_System::redirect($target);
    }

    private function storePaymentResponse(string $trxnId, int $contributionId, array $get): void {
        try {
            \CRM_Core_DAO::executeQuery(
                'INSERT INTO civicrm_bb_payment_responses
                 (trxn_id, cid, cardtype, cardnum, cardexp, firstpay, installments, response, amount, is_regular, created_at)
                 VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, NOW())',
                [
                    1  => [$trxnId, 'String'],
                    2  => [$contributionId, 'String'],
                    3  => [$get['credit_card_company_issuer'] ?? '', 'String'],
                    4  => [$get['credit_card_number'] ?? '', 'String'],
                    5  => [$get['credit_card_exp_date'] ?? '', 'String'],
                    6  => [$get['first_payment_total'] ?? 0, 'String'],
                    7  => [$get['total_payments'] ?? 1, 'String'],
                    8  => [http_build_query($get), 'String'],
                    9  => [$get['debit_total'] ?? 0, 'String'],
                    10 => [1, 'String'],
                ]
            );
        } catch (\Exception $e) {
            \Civi::log('BBPriorityCC')->warning('EmvComplete storePaymentResponse error: ' . $e->getMessage());
        }
    }
}
