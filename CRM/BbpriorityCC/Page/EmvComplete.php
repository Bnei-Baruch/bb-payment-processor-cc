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
        $userKey        = $get['user_key'] ?? '';

        if (!$contributionId || $success !== '1' || !$transactionId || !$userKey) {
            \Civi::log('BBPriorityCC')->warning('EmvComplete: missing required callback params', [
                'success'        => $success,
                'transaction_id' => $transactionId,
                'cid'            => $contributionId,
                'user_key'       => $userKey ? '[present]' : '[missing]',
            ]);
            $this->redirectOut($returnUrl, 'error=1');
            return;
        }

        // Server-to-server confirmation against the external payment service.
        // The service only returns SUCCESS when its DB has status='valid' for this
        // UserKey with matching price/currency/sku/reference/organization —
        // meaning Pelecard's GetTransaction + ValidateByUniqueKey already passed.
        try {
            if (!$this->confirmWithExternalService($contributionId, $userKey)) {
                \Civi::log('BBPriorityCC')->error('EmvComplete: external confirmation FAILED', [
                    'cid'      => $contributionId,
                    'user_key' => $userKey,
                ]);
                $this->redirectOut($returnUrl, 'error=1');
                return;
            }
        } catch (\Exception $e) {
            \Civi::log('BBPriorityCC')->error('EmvComplete: confirmation exception: ' . $e->getMessage());
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

    /**
     * Calls /emv/confirm on the external payment service server-to-server.
     * Fetches contribution + financial account data to supply all fields that
     * db.Confirm() cross-checks (price, currency, sku, reference, organization).
     */
    private function confirmWithExternalService(int $contributionId, string $userKey): bool {
        // Fetch contribution basics
        $contribution = Contribution::get(false)
            ->addSelect('total_amount', 'currency', 'payment_instrument_id')
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->single();

        // Fetch processor via financial trxn to get url_site, subject (prefix), and organization
        $processorId = \CRM_Core_DAO::singleValueQuery(
            'SELECT ft.payment_processor_id
             FROM civicrm_financial_trxn ft
             JOIN civicrm_entity_financial_trxn eft ON eft.financial_trxn_id = ft.id
             WHERE eft.entity_table = "civicrm_contribution" AND eft.entity_id = %1
             ORDER BY ft.id DESC LIMIT 1',
            [1 => [$contributionId, 'Integer']]
        );

        if (!$processorId) {
            throw new \RuntimeException("EmvComplete: no payment processor found for contribution {$contributionId}");
        }

        $processor = \Civi\Api4\PaymentProcessor::get(false)
            ->addSelect('url_site', 'subject')
            ->addWhere('id', '=', $processorId)
            ->execute()
            ->single();

        $baseUrl = rtrim($processor['url_site'] ?? '', '/');
        $prefix  = $processor['subject'] ?? '';
        if (!$baseUrl || !$prefix) {
            throw new \RuntimeException("EmvComplete: processor {$processorId} missing url_site or subject");
        }

        // Fetch SKU (accounting_code) and organization (nick_name) from financial account
        $financialTypeId = \CRM_Core_DAO::singleValueQuery(
            'SELECT financial_type_id FROM civicrm_contribution WHERE id = %1',
            [1 => [$contributionId, 'Integer']]
        );
        $financialAccountId = \CRM_Core_DAO::singleValueQuery(
            'SELECT financial_account_id FROM civicrm_entity_financial_account
             WHERE entity_table = "civicrm_financial_type" AND entity_id = %1
             AND account_relationship = (SELECT id FROM civicrm_option_value
                WHERE option_group_id = (SELECT id FROM civicrm_option_group WHERE name = "account_relationship")
                AND name = "Income Account is" LIMIT 1)
             LIMIT 1',
            [1 => [$financialTypeId, 'Integer']]
        );

        $financialAccount = \Civi\Api4\FinancialAccount::get(false)
            ->addSelect('accounting_code', 'contact_id')
            ->addWhere('id', '=', $financialAccountId)
            ->execute()
            ->single();

        $sku = $financialAccount['accounting_code'] ?? '';
        $organization = \CRM_Core_DAO::singleValueQuery(
            'SELECT nick_name FROM civicrm_contact WHERE id = %1',
            [1 => [(int)$financialAccount['contact_id'], 'Integer']]
        );

        $body = [
            'UserKey'      => $userKey,
            'Price'        => (float)$contribution['total_amount'],
            'Currency'     => $contribution['currency'],
            'SKU'          => $sku,
            'Reference'    => $prefix . $contributionId,
            'Organization' => $organization,
        ];

        \Civi::log('BBPriorityCC')->info('EmvComplete: calling /emv/confirm', $body);

        $response = \Drupal::httpClient()->post($baseUrl . '/emv/confirm', [
            'json'    => $body,
            'timeout' => 15,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        \Civi::log('BBPriorityCC')->info('EmvComplete: /emv/confirm response', $data ?? []);

        return ($data['status'] ?? '') === 'SUCCESS';
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
