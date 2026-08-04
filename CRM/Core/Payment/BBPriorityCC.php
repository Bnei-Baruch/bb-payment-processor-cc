<?php
/**
 *
 * @package BBPriorityCC [after AuthorizeNet Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

use Drupal\Core\Language\LanguageInterface;
use Civi\Api4\FinancialTrxn;
use Civi\Api4\Contribution;
use Civi\Api4\EntityFinancialAccount;
use Civi\Api4\FinancialAccount;
use Civi\Api4\Contact;
use Civi\Api4\CustomField;
use Civi\Api4\PaymentProcessorType;
use Civi\Api4\PaymentProcessor;
use CRM\BBPelecard\API\Pelecard;
use CRM\BBPelecard\Utils\ErrorCodes;
use CRM\BBPelecard\Payment\BBPriorityBaseProcessor;
use CRM\BBPelecard\Payment\ActivityUpdater;

require_once 'CRM/Core/Payment.php';
require_once 'BBPriorityCCIPN.php';

/**
 * BBPriorityCC payment processor
 */
class CRM_Core_Payment_BBPriorityCC extends BBPriorityBaseProcessor {

  /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param $paymentProcessor
   *
   * @return \CRM_Core_Payment_BBPriorityCC
   */
  public function __construct(string $mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_setParam('processorName', 'BB Payment CC');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig(): ?string {
    $error = [];
    if (empty($this->_paymentProcessor["user_name"])) {
      $error[] = ts("Merchant Name is not set in the BBPCC Payment Processor settings.");
    }
    if (empty($this->_paymentProcessor["password"])) {
      $error[] = ts("Merchant Password is not set in the BBPCC Payment Processor settings.");
    }
    if (empty($this->_paymentProcessor["url_site"])) {
      $error[] = ts("EMV Base URL is not set in the BBPCC Payment Processor settings.");
    }
    if (empty($this->_paymentProcessor["subject"])) {
      $error[] = ts("Reference Prefix is not set in the BBPCC Payment Processor settings.");
    }
    return !empty($error) ? implode("<p>", $error) : NULL;
  }

  protected function debugMessage($params) {
    \Drupal::logger('payment_processor')->notice('@timestamp doPayment received: @params', [
      '@timestamp' => date('Y-m-d H:i:s'),
      '@params' => print_r($params, TRUE)
    ]);

    // Debug code for reference:
    #$debugData = [
    #'timestamp' => date('Y-m-d H:i:s'),
    #'response' => $params,
    #];
    #file_put_contents('/sites/dev.org.kbb1.com/web/sites/default/files/civicrm/ConfigAndLog/refund_debug.log',
    #json_encode($debugData, JSON_PRETTY_PRINT) . "\n",
    #FILE_APPEND | LOCK_EX
    #);
  }

  public function doRefund(&$params) {
    // Get the original contribution
    try {
      $original = \Civi\Api4\Contribution::get(false)
        ->addWhere('id', '=', $params['contribution_id'])
        ->execute()
        ->first();
      if (empty($original)) {
        return ['success' => false, 'message' => 'Unable to find original contribution'];
      }
    } catch (Exception $e) {
      return ['success' => false, 'message' => 'Error fetching original contribution: ' . $e->getMessage()];
    }

    // Try to get the amount from various possible keys
    $total_amount = $params['amount'] ?? $params['total_amount'] ?? 0;

    // If still 0, use the original contribution amount (full refund)
    if ($total_amount == 0 && !empty($original['total_amount'])) {
      $total_amount = abs($original['total_amount']);
    }

    // Get refund reason
    $refundSource = !empty($params['source']) ? 'Refund ' . $params['source'] : 'Refund';
    $contactId = $original['contact_id'];
    $originalId = $original['id'];
    $currencyId = $original['currency'];

    // Get token from Contributions custom group or Customer custom group
    $ctoken = $this->getToken($originalId, 'Contribution', 'Payment_details', 'token');
    $gtoken = $this->getToken($contactId, 'Contact', 'general_token', 'gtoken');
    if ($ctoken == "" && $gtoken == "") {
      return ['success' => false, 'message' => 'Unable to refund without any token'];
    }
    $success = false;
    $refundTrxnId = null;

    // Refund using ctoken or gtoken — creates a new negative contribution
    try {
      $total_amount = -$total_amount;

      // Create Pending contribution
      $creditCard = \Civi\Api4\OptionValue::get(false)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'payment_instrument')
        ->addWhere('name', '=', self::PAYMENT_INSTRUMENT_CREDIT_CARD)
        ->execute()
        ->first();
      $creditCardId = $creditCard['value'];

      $refund = \Civi\Api4\Contribution::create(false)
        ->setValues([
          'contact_id' => $contactId,
          'total_amount' => $total_amount,
          'currency' => $currencyId,
          'source' => $refundSource,
          'cancel_reason' => 'Refund ' . $originalId,
          'receive_date' => date('Y-m-d H:i:s'),
          'contribution_status_id:name' => self::PAYMENT_STATUS_PENDING,
          'payment_instrument_id' => $creditCardId,
          'financial_type_id' => $params['financial_type_id'],
        ])
        ->execute()[0];
      $contributionId = $refund['id'];

      $trxn_id = $this->setTrxnId($this->_mode);
      $financialTypeID = $this->getFinancialTypeId($params);
      $financial_account_id = $this->getFinancialAccountId($financialTypeID);
      $this->createFinancialTrxn($contributionId, $total_amount, $trxn_id, $this->_paymentProcessor["id"], $financial_account_id, $currencyId);

      $response = ['success' => false];
      if ($ctoken) {
        $response = $this->payByToken($ctoken, $total_amount, $currencyId, $contributionId);
      }
      if (!$response['success'] && $gtoken && $gtoken !== $ctoken) {
        $response = $this->payByToken($gtoken, $total_amount, $currencyId, $contributionId);
      }
      if ($response['success']) {
        $success = true;
        $message = "Refund processed successfully";
        // Update contribution status to Completed + fill in data from $response
        $refundTrxnId = $response['PelecardTransactionId'] ?? 'refund_' . time();

        // Store refund response so bb2prio can extract VoucherId for Priority credit note.
        if (!empty($response['data'])) {
          \CRM_Core_DAO::executeQuery(
            'INSERT IGNORE INTO civicrm_bb_payment_responses
             (trxn_id, cid, response, amount, is_regular, created_at)
             VALUES (%1, %2, %3, %4, 0, NOW())',
            [
              1 => [$refundTrxnId, 'String'],
              2 => [$contributionId, 'Integer'],
              3 => [$response['data'], 'String'],
              4 => [$total_amount, 'Float'],
            ]
          );
        }
        /* Due to CiviCRM API4 bug:
        \Civi\Api4\Contribution::update(false)
          ->addWhere('id', '=', $contributionId)
          ->addValue('contribution_status_id:name', self::PAYMENT_STATUS_COMPLETED)
          ->execute();
        */
        \CRM_Core_DAO::executeQuery(
          "UPDATE civicrm_contribution SET contribution_status_id = %1 WHERE id = %2",
          [
            1 => [CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', self::PAYMENT_STATUS_COMPLETED), 'Integer'],
            2 => [$contributionId, 'Integer'],
          ]
        );
      } else {
        $refundTrxnId = 'refund_' . time();
        $message = "Refund failed: " . ($response['code'] ?? '') . " " . ($response['error_message'] ?? '');
      }
    } catch (Exception $e) {
      $message = "Error processing refund: " . $e->getMessage();
    }

    // Result
    return [
      'success' => $success,
      'message' => $message,
      'trxn_id' => $refundTrxnId,
    ];
  }

  /* DEBUG - for reference
       echo "<pre>";
       var_dump($this->_paymentProcessor);
       var_dump($params);
       echo "</pre>";
       http_build_query();
       exit();
       echo static::formatBacktrace(debug_backtrace());
    */
  function doPayment(&$params, $component = 'contribute') {
    if ($component != 'contribute' && $component != 'event') {
      Civi::log()->error('bbprioritycc_payment_exception', ['context' => ['message' => "Component '{$component}' is invalid."]]);
      CRM_Utils_System::civiExit();
    }
    $this->_component = $component;

    $lang = strtoupper(\Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId());
    $lang = in_array($lang, ['EN', 'HE', 'RU', 'ES']) ? $lang : 'EN';

    $statuses = CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate');
    $invoiceID = $this->_getParam('invoiceID');
    $contributionID = $params['contributionID'] ?? NULL;
    $contactID = $params['contactID'];

    if ($this->checkDupe($invoiceID, $contributionID)) {
      throw new PaymentProcessorException('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.', 9004);
    }

    // If we have a $0 amount, skip call to processor and set payment_status to Completed.
    // Conceivably a processor might override this - perhaps for setting up a token - but we don't
    // have an example of that at the moment.
    if ($params['amount'] == 0) {
      $result = [];
      $result['payment_status_id'] = array_search('Completed', $statuses);
      $result['payment_status'] = 'Completed';
      return $result;
    }

    $params['trxn_id'] = $this->setTrxnId($this->_mode);
    // Total amount is from the form contribution field
    $amount = $params['total_amount'];
    if ($amount < 0) {
      throw new PaymentProcessorException(ts('Amount must be positive!!!'), 9004);
    }
    $params['gross_amount'] = $amount;
    $params['fee_amount'] = 0;
    $params['net_amount'] = $amount;

    if (array_key_exists('successURL', $params)) {
      // webform
      $returnURL = $params['successURL'];
      $cancelURL = $params['cancelURL'];
    } else {
      $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
      $cancel = ($component == 'event') ? '_qf_Register_display' : '_qf_Main_display';
      $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey={$params['qfKey']}", TRUE, NULL, FALSE);
      $cancelUrlString = "$cancel=1&cancel=1&qfKey={$params['qfKey']}";
      if ($params['is_recur'] ?? false) {
        $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$contributionID}";
      }
      $cancelURL = CRM_Utils_System::url($url, $cancelUrlString, TRUE, NULL, FALSE);
    }

    $goodUrl = CRM_Utils_System::url(
      'civicrm/emv/complete',
      'cid=' . $contributionID . '&rurl=' . urlencode($returnURL),
      TRUE
    );

    ActivityUpdater::updateActivitiesViaContribution($contributionID, $contactID);
    ActivityUpdater::updateActivitiesViaPendingActivities($contributionID);

    $financialTypeID = $this->getFinancialTypeId($params);
    $financial_account_id = $this->getFinancialAccountId($financialTypeID);

    $financialAccount = FinancialAccount::get(false)
      ->addSelect('contact_id', 'account_type_code', 'description', 'accounting_code', 'is_deductible')
      ->addWhere('id', '=', $financial_account_id)
      ->execute()
      ->single();

    $nick_name = $this->getEntityFieldValue(Contact::class, 'nick_name', ['id' => $financialAccount['contact_id']]);
    $installments = (int)($financialAccount['account_type_code'] ?? 0);
    $min_amount = (int)($financialAccount['description'] ?? 0);
    $sku = $financialAccount['accounting_code'] ?? '';
    $vat = ($financialAccount['is_deductible'] ?? false) ? 'y' : 'n';

    $currencyName = $params['custom_1706'] ?? $params['currencyID'];
    Contribution::update(false)
      ->addWhere('id', '=', $contributionID)
      ->addValue('currency', $currencyName)
      ->execute();

    $this->createFinancialTrxn($contributionID, $amount, $params['trxn_id'], $this->_paymentProcessor['id'], $financial_account_id, $currencyName);

    $maxInstallments = ($installments > 0 && $amount >= $min_amount) ? $installments : 1;

    $contactInfo = $this->fetchContactInfo($contactID, (int)$contributionID);
    $details = $this->fetchParticipantDetails($params['participantID'] ?? null);
    [$taxType, $taxId] = $this->fetchContributionTaxInfo((int)$contributionID);

    $body = [
      'UserKey'      => $params['qfKey'],
      'GoodURL'      => $goodUrl,
      'ErrorURL'     => $cancelURL,
      'CancelURL'    => $cancelURL,
      'IsRecurring'  => (bool)($params['is_recur'] ?? false),
      'Name'         => $contactInfo['name'],
      'Price'        => $amount,
      'Currency'     => $currencyName,
      'Email'        => $contactInfo['email'],
      'Phone'        => $contactInfo['phone'],
      'Street'       => $contactInfo['street'],
      'City'         => $contactInfo['city'],
      'Country'      => $contactInfo['country'],
      'Participants' => '1',
      'Details'      => $details,
      'SKU'          => $sku,
      'VAT'          => $vat,
      'Installments' => $maxInstallments,
      'Language'     => $lang,
      'Reference'    => $this->getReferencePrefix() . $contributionID,
      'Organization' => $nick_name,
      'TaxType'      => $taxType,
      'TaxId'        => $taxId,
    ];

    $redirectUrl = $this->callEmvApi('/emv/new', $body);
    if ($redirectUrl === null) {
      return false;
    }

    $template = CRM_Core_Smarty::singleton();
    $template->assign('url', $redirectUrl);
    print $template->fetch('CRM/Core/Payment/BbpriorityCC.tpl');
    CRM_Utils_System::civiExit();
  }

  public function handlePaymentNotification() {
    $ipnClass = new CRM_Core_Payment_BBPriorityCCIPN(array_merge($_GET, $_REQUEST));
    $input = $ids = [];
    $ipnClass->getInput($input, $ids);
    $ipnClass->main($this->_paymentProcessor, $input, $ids);
  }

  /**
   * Payment constants
   */
  private const DEBIT_ACTION = 'J4';
  private const SHOP_NUMBER = '100';
  private const PAYMENT_INSTRUMENT_CREDIT_CARD = 'Credit Card';

  function getToken($entity_id, $entity, $group_name, $field_name) {
    $token = "";
    try {
      // First, we need to get the custom field ID
      $customField = CustomField::get(false)
        ->addWhere('custom_group_id:name', '=', $group_name)
        ->addWhere('name', '=', $field_name)
        ->execute();
      if ($customField->count() > 0) {
        $customFieldId = $customField->first()['id'];
        // Now get the entity with the custom field using group.field notation
        $entityClass = "\\Civi\\Api4\\$entity";
        $result = $entityClass::get(false)
          ->addSelect("{$group_name}.{$field_name}")
          ->addWhere('id', '=', $entity_id)
          ->execute()
          ->first();
        // Try both notations: group.field and custom_id
        if (!empty($result["{$group_name}.{$field_name}"])) {
          $token = $result["{$group_name}.{$field_name}"];
        } elseif (!empty($result["custom_$customFieldId"])) {
          $token = $result["custom_$customFieldId"];
        }
      }
    } catch (Exception $e) {
      \Civi::log('BBPriorityCC')->error("Error retrieving token for {$entity} ID {$entity_id}: " . $e->getMessage());
    }
    return $token;
  }

  protected function payByToken(string $token, float $amount, string $currencyID, int $contributionId): array {
    $pelecard = new Pelecard(Pelecard::TYPE_CC, (bool)($this->_paymentProcessor['is_test'] ?? false));
    $pelecard->setParameter("terminalNumber", $this->_paymentProcessor["signature"]);
    $pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
    $pelecard->setParameter("password", $this->_paymentProcessor["password"]);
    $pelecard->setParameter("TokenForTerminal", $this->_paymentProcessor["signature"]);
    $pelecard->setParameter("ActionType", self::DEBIT_ACTION); // Debit action (J4 = refund)
    $pelecard->setParameter("ShopNo", self::SHOP_NUMBER);
    $pelecard->setParameter("token", $token);
    $pelecard->setParameter("ParamX", $this->getReferencePrefix() . $contributionId);
    // For refunds (ActionType J4), Pelecard expects negative amount
    $total = $amount * 100;
    $pelecard->setParameter("total", $total);
    $currency = $this->getCurrencyCode(['currencyID' => $currencyID]);
    $pelecard->setParameter("Currency", $currency);
    $result = $pelecard->singlePayment();
    if (!$result['success']) {
      $response['success'] = false;
      $response['code'] = $result['code'];
      $response['error_message'] = $result['error_message'];
      return $response;
    }
    $response['success']               = true;
    $response['PelecardTransactionId'] = $result['PelecardTransactionId'];
    $response['approval']              = $result['approval'] ?? '';
    $response['data']                  = $result['data'] ?? '';
    return $response;
  }

  private function fetchContactInfo(int $contactID, int $contributionID): array {
    $result = \CRM_Core_DAO::executeQuery("
      SELECT
        c.display_name AS name,
        (SELECT e.email FROM civicrm_email e WHERE e.contact_id = c.id LIMIT 1) AS email,
        (SELECT ph.phone FROM civicrm_phone ph WHERE ph.contact_id = c.id AND ph.is_primary = 1 LIMIT 1) AS phone,
        (SELECT a.street_address FROM civicrm_address a WHERE a.contact_id = c.id AND a.is_primary = 1 LIMIT 1) AS street,
        (SELECT a.city FROM civicrm_address a WHERE a.contact_id = c.id AND a.is_primary = 1 LIMIT 1) AS city,
        COALESCE(
          (SELECT cn.name FROM civicrm_country cn WHERE cn.iso_code =
            (SELECT bc.bb_country_1629 FROM civicrm_value_bb_country_256 bc WHERE bc.entity_id = co.id LIMIT 1)
          LIMIT 1),
          (SELECT cn.name FROM civicrm_country cn WHERE cn.id =
            (SELECT a.country_id FROM civicrm_address a WHERE a.contact_id = c.id AND a.is_primary = 1 LIMIT 1)
          LIMIT 1)
        ) AS country
      FROM civicrm_contact c
      JOIN civicrm_contribution co ON co.id = %1 AND co.contact_id = c.id
      WHERE c.id = %2
    ", [
      1 => [$contributionID, 'Integer'],
      2 => [$contactID, 'Integer'],
    ]);
    if ($result->fetch()) {
      return [
        'name'    => $result->name ?? '',
        'email'   => $result->email ?? '',
        'phone'   => $result->phone ?? '',
        'street'  => $result->street ?? '',
        'city'    => $result->city ?? '',
        'country' => $result->country ?? '',
      ];
    }
    return ['name' => '', 'email' => '', 'phone' => '', 'street' => '', 'city' => '', 'country' => ''];
  }

  private function fetchParticipantDetails(?int $participantID): string {
    if (!$participantID) {
      return '1';
    }
    $result = \CRM_Core_DAO::executeQuery(
      'SELECT COUNT(1) + 1 AS cnt FROM civicrm_participant WHERE registered_by_id = %1',
      [1 => [$participantID, 'Integer']]
    );
    return $result->fetch() ? (string)($result->cnt ?? 1) : '1';
  }

  private function fetchContributionTaxInfo(int $contributionID): array {
    try {
      $fields = \Civi\Api4\CustomField::get(false)
        ->addSelect('name', 'custom_group_id.name')
        ->addWhere('name', 'IN', ['QAME_WTAXNUMEXPL', 'QAMS_VATNUM'])
        ->addWhere('custom_group_id.extends', '=', 'Contribution')
        ->execute();
      if ($fields->count() === 0) {
        return ['', ''];
      }
      $selects = [];
      foreach ($fields as $field) {
        $selects[] = $field['custom_group_id.name'] . '.' . $field['name'];
      }
      $contribution = \Civi\Api4\Contribution::get(false)
        ->addSelect(...$selects)
        ->addWhere('id', '=', $contributionID)
        ->execute()
        ->first();
      $taxType = '';
      $taxId = '';
      foreach ($fields as $field) {
        $key = $field['custom_group_id.name'] . '.' . $field['name'];
        if ($field['name'] === 'QAME_WTAXNUMEXPL') {
          $taxType = (string)($contribution[$key] ?? '');
        } elseif ($field['name'] === 'QAMS_VATNUM') {
          $taxId = (string)($contribution[$key] ?? '');
        }
      }
      return [$taxType, $taxId];
    } catch (\Exception $e) {
      return ['', ''];
    }
  }

  private function getReferencePrefix(): string {
    $prefix = $this->_paymentProcessor['subject'] ?? '';
    if (empty($prefix)) {
      throw new \RuntimeException('BBPriorityCC: Reference Prefix (subject) is not configured in payment processor settings.');
    }
    return $prefix;
  }

  private function callEmvApi(string $path, array $body): ?string {
    $baseUrl = rtrim($this->_paymentProcessor['url_site'] ?? '', '/');
    if (empty($baseUrl)) {
      throw new \RuntimeException('BBPriorityCC: EMV Base URL (url_site) is not configured in payment processor settings.');
    }
    try {
      $response = \Drupal::httpClient()->post($baseUrl . $path, [
        'json'    => $body,
        'timeout' => 30,
      ]);
      $data = json_decode($response->getBody()->getContents(), true);
      if (($data['status'] ?? '') === 'success' && !empty($data['url'])) {
        return $data['url'];
      }
      \Civi::log('BBPriorityCC')->error('EMV ' . $path . ' error: ' . ($data['error'] ?? 'unknown'));
      return null;
    } catch (\RuntimeException $e) {
      throw $e;
    } catch (\Exception $e) {
      \Civi::log('BBPriorityCC')->error('EMV ' . $path . ' exception: ' . $e->getMessage());
      return null;
    }
  }
}
