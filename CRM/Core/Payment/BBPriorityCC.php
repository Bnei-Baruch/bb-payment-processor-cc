<?php
/**
 *
 * @package BBPriorityCC [after AuthorizeNet Payment Processor]
 * @author Gregory Shilin <gshilin@gmail.com>
 */

use Drupal\Core\Language\LanguageInterface;

require_once 'CRM/Core/Payment.php';
require_once 'includes/PelecardAPICC.php';
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
            throw new CRM_Core_Exception('Could not find contribution max id');
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
        $error = array();

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
			$debugData = [
				'timestamp' => date('Y-m-d H:i:s'),
				'response' => $params,
			];

			file_put_contents('/sites/dev.org.kbb1.com/web/sites/default/files/civicrm/ConfigAndLog/refund_debug.log', 
				json_encode($debugData, JSON_PRETTY_PRINT) . "\n", 
				FILE_APPEND | LOCK_EX
			); 
	  }

    /**
     * Process a refund transaction
     *
     * @param array $params
     *   Contains:
     *   - contribution_id: The ID of the contribution being refunded
     *   - total_amount: The amount to be refunded
     *   - trxn_id: The transaction ID of the original payment (if available)
     *   - refund_reason: The reason for the refund
     *
     * @return array
     *   The result of the refund operation:
     *   - success: TRUE if refund processed successfully, FALSE otherwise
     *   - message: A descriptive message about the result
     *   - payment_status_id: A status ID for the payment (if applicable)
     */
    function doRefund(&$params) {
      // Required parameters
      if (empty($params['contribution_id'])) {
        return [
          'success' => false,
          'message' => 'Missing required parameter: contribution_id',
        ];
      }

      if (!isset($params['total_amount'])) {
        return [
          'success' => false,
          'message' => 'Missing required parameter: total_amount',
        ];
      }

      // Get the original contribution
      try {
        $original = civicrm_api3('Contribution', 'getsingle', [
          'id' => $params['contribution_id'],
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        return [
          'success' => false,
          'message' => 'Could not find original contribution: ' . $e->getMessage(),
        ];
      }

      // Validate the refund amount
			$total_amount = $params['total_amount'];
      if ($total_amount <= 0) {
        return [
          'success' => false,
          'message' => 'Refund amount must be greater than zero',
        ];
      }
  
      if ($total_amount > $original['total_amount']) {
        return [
          'success' => false,
          'message' => 'Refund amount cannot exceed the original contribution amount',
        ];
      }

      // Get refund reason
      $refundSource = !empty($params['source']) ? 'Refund ' . $params['source'] : 'Refund';
  
			$contactId = $original['contact_id'];
			$originalId = $original['id'];

      // Get token from Contributions custom group or Customer custom group
      $ctoken = $this->getCToken($originalId);
      $gtoken = $this->getGToken($contactId);
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
      $success = false;
      try {
				$total_amount = -$total_amount;

				// Create Pending contribution
				$creditCard = \Civi\Api4\OptionValue::get()
					->addSelect('value')
					->addWhere('option_group_id:name', '=', 'payment_instrument')
					->addWhere('name', '=', 'Credit Card')
					->execute()
					->first();
				$creditCardId = $creditCard['value'];

				$refund = \Civi\Api4\Contribution::create(false)
					->setValues([
						'contact_id' => $contactId,
						'total_amount' => $total_amount,
						'source' => $refundSource,
						'cancel_reason' => 'Refund ' . $originalId,
						'receive_date' => date('Y-m-d H:i:s'),
						'contribution_status_id:name' => 'Pending',
						'payment_instrument_id' => $creditCardId,
						'financial_type_id' => $params['financial_type_id'],
					])
					->execute()[0];
				$contributionId = $refund['id'];
				$currencyId = $params['currencyID'];

				if ($ctoken) {
					$token = $ctoken;
					$response = $this->payByToken($ctoken, $total_amount, $currencyId, $contributionId);
					if (!$response['success'] && $gtoken && $gtoken !== $ctoken) {
					  $token = $gtoken;
						$response = $this->payByToken($gtoken, $total_amount, $currencyId, $contributionId);
					}
				}
        if ($response['success']) {
          $success = true;
          $message = "Refund processed successfully";

					// Update contribution status to Completed + fill in data from $response
          $refundTrxnId = $response['PelecardTransactionId'] ?? 'refund_' . time();

					\Civi\Api4\Contribution::update(false)
						->addWhere('id', '=', $contributionId)
						->addValue('contribution_status_id:name', 'Completed')
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

		/* DEBUG
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
        if ($this->checkDupe($invoiceID, $contributionID)) {
            throw new PaymentProcessorException('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.', 9004);
        }

        // If we have a $0 amount, skip call to processor and set payment_status to Completed.
        // Conceivably a processor might override this - perhaps for setting up a token - but we don't
        // have an example of that at the moment.
        if ($params['amount'] == 0) {
            $result = array();
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
                $cancelUrlString .= "&isRecur=1&recurId={$params['contributionRecurID']}&contribId={$params['contributionID']}";
            }

            $cancelURL = CRM_Utils_System::url(
                $url,
                $cancelUrlString,
                TRUE, NULL, FALSE);
        }

        $merchantUrlParams = "contactID={$params['contactID']}&contributionID={$params['contributionID']}";
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

        $pelecard = new PelecardAPICC;
        $merchantUrl = $base_url . 'civicrm/payment/ipn?processor_id=' . $this->_paymentProcessor["id"] . '&mode=' . $this->_mode
            . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&' . $merchantUrlParams
            . '&returnURL=' . $pelecard->base64_url_encode($returnURL);

        $financialTypeID = self::array_column_recursive_first($params, "financialTypeID");
        if (empty($financialTypeID)) {
            $financialTypeID = self::array_column_recursive_first($params, "financial_type_id");
        }
        $financial_account_id = civicrm_api3('EntityFinancialAccount', 'getvalue', array('return' => "financial_account_id", 'entity_id' => $financialTypeID, 'account_relationship' => 1,));
        $contact_id = civicrm_api3('FinancialAccount', 'getvalue', array('return' => "contact_id", 'id' => $financial_account_id, 'account_relationship' => 1,));
        $nick_name = civicrm_api3('Contact', 'getvalue', array('return' => "nick_name", 'id' => $contact_id, 'account_relationship' => 1,));

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
        } elseif ($nick_name == 'arvut2') {
            if ($lang == 'HE') {
                $pelecard->setParameter("TopText", 'תנועת הערבות לאיחוד העם');
                $pelecard->setParameter("BottomText", '© תנועת הערבות לאיחוד העם');
                $pelecard->setCS('cs_payments', 'מספר תשלומים (לתושבי ישראל בלבד)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'אני מסכים עם תנאי השימוש');
            } elseif ($lang == 'RU') {
                $pelecard->setParameter("TopText", 'Общественное движение «Арвут»');
                $pelecard->setParameter("BottomText", '© Общественное движение «Арвут»');
                $pelecard->setCS('cs_payments', 'Количество платежей (только для жителей Израиля)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'Я согласен с условиями обслуживания');
            } else {
                $pelecard->setParameter("TopText", 'The Arvut Social Movement');
                $pelecard->setParameter("BottomText", '© The Arvut Social Movement');
                $pelecard->setCS('cs_payments', 'Number of installments (for Israel residents only)');
                $pelecard->setParameter('ShowConfirmationCheckbox', 'True');
                $pelecard->setParameter('TextOnConfirmationBox', 'I agree with the terms of service');
            }
            $pelecard->setParameter('ConfirmationLink', 'https://www.arvut.org/he/2012-04-14-03-44-47/2021-04-04-07-45-59');
            $pelecard->setParameter("LogoUrl", "https://checkout.arvut.org/arvut_logo.png");
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
        if ($params["currencyID"] == "EUR") {
            $currency = 978;
        } elseif ($params["currencyID"] == "USD") {
            $currency = 2;
        } else { // ILS -- default
            $currency = 1;
        }
        $pelecard->setParameter("Currency", $currency);
        $pelecard->setParameter("MinPayments", 1);

        $installments = civicrm_api3('FinancialAccount', 'getvalue', array('return' => "account_type_code", 'id' => $financial_account_id,));
        try {
            $min_amount = civicrm_api3('FinancialAccount', 'getvalue', array('return' => "description", 'id' => $financial_account_id,));
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

        $input = $ids = array();
        $ipnClass->getInput($input, $ids);

        $ipnClass->main($this->_paymentProcessor, $input, $ids);
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

    /**
     * Get the value of a field if set.
     *
     * @param string $field
     *   The field.
     *
     * @param bool $xmlSafe
     * @return mixed
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
     * Set a field to the specified value.  Value must be a scalar (int,
     * float, string, or boolean)
     *
     * @param string $field
     * @param string $value
     *
     */
    public function _setParam(string $field, string $value) {
        $this->_params[$field] = $value;
    }

    function getGToken($contact_id) {
      return $this->getToken($contact_id, 'Contact', 'general_token', 'gtoken');
    }

    function getCToken($contribution_id) {
      return $this->getToken($contribution_id, 'Contribution', 'Payment_details', 'token');
    }

    function getToken($entity_id, $entity, $group_name, $field_name) {
      $token = "";

      try {
        // First, we need to get the custom field ID
        $customField = civicrm_api3('CustomField', 'get', [
          'sequential' => 1,
          'custom_group_id' => $group_name,
          'name' => $field_name,
        ]);
    
        if ($customField['count'] > 0) {
          $customFieldId = $customField['values'][0]['id'];
      
          // Now get the entity with the custom field explicitly requested
          $result = civicrm_api3($entity, 'get', [
            'return' => ["custom_$customFieldId"],
            'id' => $entity_id,
          ]);
      
          if (!empty($result['values'][$entity_id]["custom_$customFieldId"])) {
            $token = $result['values'][$entity_id]["custom_$customFieldId"];
          }
        }
      } catch (CiviCRM_API3_Exception $e) {
      }
      return $token;
    }

		protected function payByToken($token, $amount, $currencyID, $contributionId) {
			$pelecard = new PelecardAPICC;
			$pelecard->setParameter("terminalNumber", $this->_paymentProcessor["signature"]);
			$pelecard->setParameter("user", $this->_paymentProcessor["user_name"]);
			$pelecard->setParameter("password", $this->_paymentProcessor["password"]);

			$pelecard->setParameter("ActionType", 'J4'); // Debit action
			$pelecard->setParameter("ShopNo", "100");
			$pelecard->setParameter("token", $token);
			$pelecard->setParameter("ParamX", 'civicrm-' . $contributionId);
			$pelecard->setParameter("total", $amount * 100);

			if ($currencyID == "EUR") {
					$currency = 978;
			} elseif ($currencyID == "USD") {
					$currency = 2;
			} else { // ILS -- default
					$currency = 1;
			}
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
}
