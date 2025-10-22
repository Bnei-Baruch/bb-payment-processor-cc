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
use CRM\BBPelecard\API\PelecardCC;
use CRM\BBPelecard\Utils\ErrorCodes;

require_once 'CRM/Core/Payment.php';
require_once 'BBPriorityCCIPN.php';

/**
 * BBPriorityCC payment processor
 */
class CRM_Core_Payment_BBPriorityCC extends CRM_Core_Payment {
    protected $_mode = NULL;

    protected $_params = [];

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

    public function setTrxnId(string $mode): string {
        $query = "SELECT MAX(trxn_id) AS trxn_id FROM civicrm_contribution WHERE trxn_id LIKE '{$mode}_%' LIMIT 1";
        $tid = CRM_Core_Dao::executeQuery($query);
        if (!$tid->fetch()) {
            throw new Exception('Could not find contribution max id');
        }
        $trxn_id = strval($tid->trxn_id);
        $trxn_id = str_replace("{$mode}_", '', $trxn_id);
        $trxn_id = intval($trxn_id) + 1;
        $uniqid = uniqid();
        return "{$mode}_{$trxn_id}_{$uniqid}";
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

        if (!empty($error)) {
            return implode("<p>", $error);
        } else {
            return NULL;
        }
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
    $original = CRM_Core_BAO_FinancialTrxn::getOriginalTrxnAndParams($params['contribution_id']);
    if (empty($original['trxn'])) {
      return [
        'success' => false,
        'message' => 'Unable to find original transaction'
      ];
    }

    $total_amount = $params['amount'];

    // Get refund reason
    $refundSource = !empty($params['source']) ? 'Refund ' . $params['source'] : 'Refund';

	$contactId = $original['contact_id'];
	$originalId = $original['id'];
	$currencyId = $original['currency'];

    // Get token from Contributions custom group or Customer custom group
    $ctoken = $this->getToken($originalId, 'Contribution', 'Payment_details', 'token');
    $gtoken = $this->getToken($contactId, 'Contact', 'general_token', 'gtoken');
    if ($ctoken == "" && $gtoken == "") {
      return [
        'success' => false,
        'message' => 'Unable to refund without any token'
      ];
    }
    $success = false;
    $refundTrxnId = null;

    // Refund using ctoken or gtoken
	// This should create a new contribution
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
	$this->createFinancialTrxn($contributionId, $total_amount, $trxn_id, $this->_paymentProcessor["id"] , $financial_account_id, $currencyId);

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
	\Civi\Api4\Contribution::update(false)
		->addWhere('id', '=', $contributionId)
		->addValue('contribution_status_id:name', self::PAYMENT_STATUS_COMPLETED)
		->execute();
      } else {
        $refundTrxnId = 'refund_' . time();
        $message = "Refund failed: " . $response['status_code'] . " " . $response['error_message'];
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
            Civi::log()->error('bbprioritycc_payment_exception',
                ['context' => [
                    'message' => "Component '{$component}' is invalid."
                ]]);
            CRM_Utils_System::civiExit();
        }
        $this->_component = $component;

        $base_url = CRM_Utils_System::baseURL();
        $uiLanguage = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
        $lang = strtoupper($uiLanguage);

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
        //Total amount is from the form contribution field
        $amount = $params['total_amount'];
        if ($amount < 0) {
            throw new PaymentProcessorException(ts('Amount must be positive!!!'), 9004);
        }
        $params['gross_amount'] = $amount;
        // Add a fee_amount so we can be sure fees are handled properly in underlying classes.
        $params['fee_amount'] = 1.50;
        $params['net_amount'] = $params['gross_amount'] - $params['fee_amount'];
        
        if (array_key_exists('successURL', $params)) {
	    // webform
            $returnURL = $params['successURL'];
            $cancelURL = $params['cancelURL'];
        } else {
            $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
            $cancel = ($component == 'event') ? '_qf_Register_display' : '_qf_Main_display';
            $returnURL = CRM_Utils_System::url($url,
                "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                TRUE, NULL, FALSE
            );

            $cancelUrlString = "$cancel=1&cancel=1&qfKey={$params['qfKey']}";
            if ($params['is_recur'] ?? false) {
                $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$contributionID}";
            }

            $cancelURL = CRM_Utils_System::url(
                $url,
                $cancelUrlString,
                TRUE, NULL, FALSE);
        }

        $merchantUrlParams = "contactID={$contactID}&contributionID={$contributionID}";
        if ($component == 'event') {
            $merchantUrlParams .= "&eventID={$params['eventID']}&participantID={$params['participantID']}";
        } else {
            $membershipID = $params['membershipID'];
            if ($membershipID) {
                $merchantUrlParams .= "&membershipID=$membershipID";
            }
            $contributionPageID = $params['contributionPageID'] ?? $params['contribution_page_id'];
            if ($contributionPageID) {
                $merchantUrlParams .= "&contributionPageID=$contributionPageID";
            }
            $relatedContactID = $params['related_contact'];
            if ($relatedContactID) {
                $merchantUrlParams .= "&relatedContactID=$relatedContactID";

                $onBehalfDupeAlert = $params['onbehalf_dupe_alert'];
                if ($onBehalfDupeAlert) {
                    $merchantUrlParams .= "&onBehalfDupeAlert=$onBehalfDupeAlert";
                }
            }
        }

	$this->updateActivitiesViaContribution($contributionID, $contactID);
	$this->updateActivitiesViaPendingActivities($contributionID);

        $pelecard = new PelecardCC();
        $merchantUrl = $base_url . 'civicrm/payment/ipn?processor_id=' . $this->_paymentProcessor["id"] . '&mode=' . $this->_mode
            . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams
            . '&returnURL=' . $pelecard->base64_url_encode($returnURL);

        $financialTypeID = $this->getFinancialTypeId($params);
        $financial_account_id = $this->getFinancialAccountId($financialTypeID);
        $contact_id = $this->getEntityFieldValue(
            FinancialAccount::class,
            'contact_id',
            ['id' => $financial_account_id]
        );
        $nick_name = $this->getEntityFieldValue(
            Contact::class,
            'nick_name',
            ['id' => $contact_id]
        );

        $currencyName = $params['custom_1706'] ?? $params['currencyID'];
        $currency = $this->getCurrencyCode($params);
	\Civi\Api4\Contribution::update(false)
		->addWhere('id', '=', $contributionID)
		->addValue('currency', $currencyName)
		->execute();

	$this->createFinancialTrxn($contributionID, $amount, $params['trxn_id'], $this->_paymentProcessor["id"] , $financial_account_id, $currencyName);

        if ($lang == 'HE') {
            $pelecard->setParameter("Language", 'he');
        } else if ($lang == 'RU') {
            $pelecard->setParameter("Language", 'ru');
        } else {
            $pelecard->setParameter("Language", 'en');
        }
        if ($nick_name == 'ben2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'בני ברוך קבלה לעם');
                $pelecard->setParameter("BottomText", '© בני ברוך קבלה לעם');
                $pelecard->setCS('cs_payments', 'מספר תשלומים (לתושבי ישראל בלבד)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Бней Барух Каббала лаАм');
                $pelecard->setParameter("BottomText", '© Бней Барух Каббала лаАм');
                $pelecard->setCS('cs_payments', 'Количество платежей (только для жителей Израиля)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'Я согласен с условиями обслуживания');
            } else {
                $pelecard->setParameter("TopText", 'Bnei Baruch Kabbalah laAm');
                $pelecard->setParameter("BottomText", '© Bnei Baruch Kabbalah laAm');
                $pelecard->setCS('cs_payments', 'Number of installments (for Israel residents only)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'I agree with the terms of service');
            }
            $pelecard->setParameter('ConfirmationLink', 'https://checkout.kabbalah.info/legacy-statement-crm-en.html');
            $pelecard->setParameter("LogoUrl", "https://checkout.kabbalah.info/logo1.png");
        } elseif ($nick_name == 'meshp18') {
            $pelecard->setParameter("TopText", 'משפחה בחיבור');
            $pelecard->setParameter("BottomText", '© משפחה בחיבור');
            $pelecard->setCS('cs_payments', 'מספר תשלומים (לתושבי ישראל בלבד)');
            $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
            $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
            $pelecard->setParameter("Language", 'HE');
            $pelecard->setParameter('ConfirmationLink', 'https://www.1family.co.il/privacy-policy/');
            $pelecard->setParameter("LogoUrl", "https://www.1family.co.il/wp-content/uploads/2019/06/cropped-Screen-Shot-2019-06-16-at-00.12.07-140x82.png");
        }

        $pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
        $pelecard->setParameter("password", $this->_paymentProcessor["password"]);
        $pelecard->setParameter("terminal", $this->_paymentProcessor["signature"]);

        $pelecard->setParameter("UserKey", $params['qfKey']);
        $pelecard->setParameter("ParamX", 'civicrm-' . $params['contributionID']);

        // $sandBoxUrl = 'https://gateway20.pelecard.biz/sandbox/landingpage?authnum=123';
        $pelecard->setParameter("GoodUrl", $merchantUrl); // ReturnUrl should be used _AFTER_ payment confirmation
        $pelecard->setParameter("ErrorUrl", $merchantUrl);
        $pelecard->setParameter("CancelUrl", $cancelURL);
        // Free amount example
        // $pelecard->setParameter("Total", 0);
        // $pelecard->setParameter("FreeTotal", true);
        // if ($lang == 'HE') {
        // $text = "אנא הכנס סכום מתאים";
        // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-he-1.css");
        // } elseif ($lang == 'RU') {
        // $text = "Введите правильную сумму";
        // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-en-1.css");
        // } else {
        // $text = "Please Select Proper Sum";
        // $pelecard->setParameter("CssURL", "https://checkout.kabbalah.info/variant-en-1.css");
        // }
        // $pelecard->setCS("cs_free_total", $text);
        $pelecard->setParameter("Total", $params["amount"] * 100);
        $pelecard->setParameter("Currency", $currency);
        $pelecard->setParameter("MinPayments", 1);

        $installments = FinancialAccount::get(false)
            ->addSelect('account_type_code')
            ->addWhere('id', '=', $financial_account_id)
            ->execute()
            ->single()['account_type_code'];
        try {
            $min_amount = FinancialAccount::get(false)
                ->addSelect('description')
                ->addWhere('id', '=', $financial_account_id)
                ->execute()
                ->single()['description'];
        } catch (Exception $e) {
            $min_amount = 0;
        }
        if ((int)$installments == 0) {
            $pelecard->setParameter("MaxPayments", 1);
        } else if ((int)$installments > 0 && $params["amount"] >= (int)$min_amount) {
            $pelecard->setParameter("MaxPayments", $installments);
        }

        $result = $pelecard->getRedirectUrl();
        $error = $result[0];
        if ($error > 0) {
            return false;
        }
        $url = $result[1];

        // Print the tpl to redirect to Pelecard
        $template = CRM_Core_Smarty::singleton();
        $template->assign('url', $url);
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
     * Helper method to get a single field value from an API4 entity
     */
    private function getEntityFieldValue(string $entityClass, string $field, array $conditions): string {
        $query = $entityClass::get(false)->addSelect($field);
        
        foreach ($conditions as $fieldName => $value) {
            $query->addWhere($fieldName, '=', $value);
        }
        
        return $query->execute()->single()[$field];
    }

    /**
     * Helper method to get financial account ID for a financial type
     */
    private function getFinancialAccountId(int $financialTypeId): int {
        return (int) $this->getEntityFieldValue(
            EntityFinancialAccount::class,
            'financial_account_id',
            ['entity_id' => $financialTypeId, 'account_relationship' => 1]
        );
    }

    /**
     * Helper method to get financial type ID from params
     */
    private function getFinancialTypeId(array $params): int {
        return (int) ($this->array_column_recursive_first($params, "financialTypeID") 
                     ?: $this->array_column_recursive_first($params, "financial_type_id"));
    }

    /**
     * Currency mapping
     */
    private const CURRENCY_MAP = [
        'EUR' => 978,
        'USD' => 2,
    ];

    /**
     * Payment constants
     */
    private const DEBIT_ACTION = 'J4';
    private const SHOP_NUMBER = '100';
    private const PAYMENT_STATUS_COMPLETED = 'Completed';
    private const PAYMENT_STATUS_PENDING = 'Pending';
    private const PAYMENT_INSTRUMENT_CREDIT_CARD = 'Credit Card';

    /**
     * Helper method to get currency code
     */
    private function getCurrencyCode(array $params): int {
        $currencyName = $params['custom_1706'] ?? $params['currencyID'];
        return self::CURRENCY_MAP[$currencyName] ?? 1; // ILS default
    }

    /**
     * Validate required parameters
     */
    private function validateRequiredParams(array $params, array $requiredFields): ?array {
        foreach ($requiredFields as $field) {
            if (!isset($params[$field]) || empty($params[$field])) {
                return [
                    'success' => false,
                    'message' => "Missing required parameter: $field",
                ];
            }
        }
        return null;
    }

    /* Find first occurrence of needle somewhere in haystack (on all levels) */
    static function array_column_recursive_first(array $haystack, $needle) {
        $found = [];
        array_walk_recursive($haystack, function ($value, $key) use (&$found, $needle) {
            if (gettype($key) == 'string' && $key == $needle) {
                $found[] = $value;
            }
        });
        return count($found) > 0 ? $found[0] : "";
    }



    function getToken($entity_id, $entity, $group_name, $field_name) {
      $token = "";

      try {
        // First, we need to get the custom field ID
        $customField = CustomField::get(false)
          ->addWhere('custom_group_id', '=', $group_name)
          ->addWhere('name', '=', $field_name)
          ->execute();
    
        if ($customField->count() > 0) {
          $customFieldId = $customField->first()['id'];
      
          // Now get the entity with the custom field explicitly requested
          $entityClass = "\\Civi\\Api4\\$entity";
          $result = $entityClass::get(false)
            ->addSelect("custom_$customFieldId")
            ->addWhere('id', '=', $entity_id)
            ->execute()
            ->first();
      
          if (!empty($result["custom_$customFieldId"])) {
            $token = $result["custom_$customFieldId"];
          }
        }
      } catch (Exception $e) {
      }
      return $token;
    }

		protected function payByToken(string $token, float $amount, string $currencyID, int $contributionId): array {
		$pelecard = new PelecardCC();
		$pelecard->setParameter("terminalNumber", $this->_paymentProcessor["signature"]);
		$pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
		$pelecard->setParameter("password", $this->_paymentProcessor["password"]);

		$pelecard->setParameter("ActionType", self::DEBIT_ACTION); // Debit action
		$pelecard->setParameter("ShopNo", self::SHOP_NUMBER);
		$pelecard->setParameter("token", $token);
		$pelecard->setParameter("ParamX", 'civicrm-' . $contributionId);
		$pelecard->setParameter("total", $amount * 100);

		$currency = $this->getCurrencyCode(['currencyID' => $currencyID]);
		$pelecard->setParameter("Currency", $currency);
		$result = $pelecard->singlePayment();
		if (!$result['success']) {
			$response['success'] = false;
			$response['code'] = $result['code'];
			$response['error_message'] = $result['error_message'];
			return $response;
		}
		$response['success'] = true;
    $response['PelecardTransactionId'] = $result['PelecardTransactionId'];
		return $response;
	}

    // for meals
    private function updateActivitiesViaContribution($contributionID, $contactID) {
	     try {
		$contributions = \Civi\Api4\Contribution::get(false)
			->addSelect('maser.note') // Get all standard and custom fields
			->addWhere('id', '=', $contributionID)
			->addWhere('contact_id', '=', $contactID)
			->execute();
		    
		if (count($contributions) === 0) {
		    return;
		}
		$note = $contributions[0]['maser.note'] ?? '';
		$ids = (strpos($note, 'Activities:') === 0) 
		    ? explode(',', substr($note, 11)) 
		    : [];
		if (empty($ids)) {
			return;
		}
		$activityIds = array_map('intval', $ids);
		try {
			// Update status_id and custom field for all activities
			$result = \Civi\Api4\Activity::update(false)
			    ->addWhere('id', 'IN', $activityIds)
			    ->addValue('status_id', 2)
			    ->addValue('Registration_for_meals.ID_for_the_payment', $contributionID)
			    ->execute();
		} catch (Exception $e) {
			// Ignore error
		}
	    } catch (Exception $e) {
		// Ignore error
	    }
    }
    
    // For events
    private function updateActivitiesViaPendingActivities($contributionID) {
     try {
	// get activity
	$today = date('d-m-Y 00:00');
	$activities = \Civi\Api4\Activity::get(false)
	    ->addSelect('id')
	    ->addJoin('ActivityContact AS ac', 'INNER', ['id', '=', 'ac.activity_id'])
	    ->addWhere('status_id', '=', '17')
	    ->addWhere('activity_type_id', '=', '182')
	    ->addWhere('activity_date_time', '>=', $today)
	    ->addOrderBy('id', 'DESC')
	    ->setLimit(1)
	    ->execute();
	if (count($activities) === 0) {
	    return;
	}
        $activity = $activities[0];

	// Update contribution with Activities:...
	\Civi\Api4\Contribution::update(false)
		->addWhere('id', '=', $contributionID)
		->addValue('maser.note', "Activities:" . $activity['id'])
		->execute();

	// Update activities with contributionID
	$result = \Civi\Api4\Activity::update(false)
	    ->addWhere('id', '=', $activity['id'])
	    ->setDebug(true)
	    ->addValue('status_id', 2)
	    ->addValue('Registration_for_event.id_for_payment', $contributionID)
	    ->execute();
    } catch (Exception $e) {
        // Ignore error
    }
  }

  // Record financial transaction
  private function createFinancialTrxn($contributionID, $totalAmount, $trxn_id, $paymentProcessorID, $financialAccountID, $currency) {
        $ftParams = [
          'total_amount' => $totalAmount,
          'contribution_id' => $contributionID,
	  'entity_id' => $contributionID,
          'trxn_id' => $trxn_id ?? $contributionID,
          'payment_processor_id' => $paymentProcessorID,
	  'currency' => $currency,
          'status_id:name' => 'Completed',
	  'to_financial_account_id' => $financialAccountID,
        ];
	FinancialTrxn::create(false)
		->setValues($ftParams)
		->execute();
  }

  /**
   * Get the value of a stored parameter.
   *
   * @param string $field
   * @param bool $xmlSafe
   * @return string
   *   value of the field, or empty string if the field is not set
   */
  public function _getParam(string $field, bool $xmlSafe = FALSE): string {
    $value = $this->_params[$field] ?? '';
    if ($xmlSafe) {
      $value = str_replace(['&', '"', "'", '<', '>'], '', $value);
    }
    return $value;
  }

  /**
   * Set a field to the specified value.
   *
   * @param string $field
   * @param mixed $value
   */
  public function _setParam(string $field, $value) {
    $this->_params[$field] = $value;
  }
}
