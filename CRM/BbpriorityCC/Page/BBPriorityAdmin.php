<?php

/**
 * @file This administrative page provides simple access to recent transactions
 * and an opportunity for the system to warn administrators about failing
 * crons .*/

require_once 'CRM/Core/Page.php';

/**
 *
 */
class CRM_BbpriorityCC_Page_BBPriorityAdmin extends CRM_Core_Page
{

    /**
     *
     */
    public function run()
    {
        // The current time.
        $this->assign('currentTime', date('Y-m-d H:i:s'));
        $this->assign('unhandledContributions', $this->getUnhandled());
        // Load the most recent requests and responses from the log files.
        $search = array();
        foreach (array('id') as $key) {
            $search[$key] = empty($_GET['search_' . $key]) ? '' : filter_var($_GET['search_' . $key], FILTER_SANITIZE_STRING);
        }
        $log = $this->getLog($search);
        $this->assign('search', $search);
        $this->assign('BBPriorityLog', $log);
        parent::run();
    }

    /**
     *
     */
    public function getUnhandled()
    {
        $sql = "SELECT count(1) unhandled
              FROM civicrm_contribution co
              WHERE
                co.contribution_status_id = (
                  SELECT value contributionStatus
                  FROM civicrm_option_value
                  WHERE option_group_id = (
                    SELECT id contributionStatusID
                    FROM civicrm_option_group
                    WHERE name = \"contribution_status\"
                    LIMIT 1
                  ) AND name = 'Completed'
                  LIMIT 1
                ) AND co.is_test = 0 
                AND co.invoice_number IS NULL 
      ";
        return CRM_Core_DAO::singleValueQuery($sql);
    }

    /**
     *
     */
    public function getLog($search = array(), $n = 40)
    {
        // Avoid sql injection attacks.
        $n = (int)$n;
        $id = $search['id'];

        $where = empty($id) ? '' : " AND co.id = " . $id;
        $limit = empty($id) ? " LIMIT " . $n : '';
        $sql = "
SELECT
  co.id ID,
  con.nick_name ORG,
  fa.accounting_code QAMO_PARTNAME,
  fa.is_deductible QAMO_VAT,
  fa.account_type_code installments,
  co.id CID,
  cc.display_name QAMO_CUSTDES,
  '' QAMO_DETAILS, 
  SUBSTRING(co.source, 1, 48) QAMO_PARTDES, 
  CASE bb.cardtype
    WHEN 1 THEN 'ISR'
    WHEN 2 THEN 'CAL'
    WHEN 3 THEN 'DIN'
    WHEN 4 THEN 'AME'
    WHEN 6 THEN 'LEU'
    ELSE 'CAS'
  END QAMO_PAYMENTCODE,
  bb.token QAMO_CARDNUM,
  bb.cardnum QAMO_PAYMENTCOUNT, 
  bb.cardexp QAMO_VALIDMONTH,
  COALESCE(bb.amount, co.total_amount) QAMO_PAYPRICE, 
  CASE co.currency
    WHEN 'USD' THEN '$'
    WHEN 'EUR' THEN 'EUR'
    ELSE 'ש\"\"ח'
  END QAMO_CURRNCY, 
  bb.installments QAMO_PAYCODE, 
  bb.firstpay QAMO_FIRSTPAY, 
  emails.email QAMO_EMAIL, 
  address.street_address QAMO_ADRESS, 
  address.city QAMO_CITY, 
  '' QAMO_CELL, 
  country.name QAMO_FROM, 
  COALESCE(bb.created_at, co.receive_date) QAMM_UDATE,
  CASE cc.preferred_language WHEN 'he_IL' THEN 'HEB' ELSE 'ENG' END QAMO_LANGUAGE
FROM civicrm_contribution co
  INNER JOIN civicrm_contact cc ON co.contact_id = cc.id
  INNER JOIN civicrm_entity_financial_account efa ON co.financial_type_id = efa.entity_id AND efa.account_relationship = 1
  INNER JOIN civicrm_financial_account fa ON fa.id = efa.financial_account_id
  INNER JOIN civicrm_contact con ON con.id = fa.contact_id
  LEFT OUTER JOIN civicrm_bb_payment_responses bb ON bb.cid = co.id
  LEFT OUTER JOIN civicrm_address address ON address.contact_id = co.contact_id
  LEFT OUTER JOIN civicrm_country country ON address.country_id = country.id
  LEFT OUTER JOIN civicrm_email emails ON emails.contact_id = co.contact_id
WHERE
  co.contribution_status_id = (
    SELECT value contributionStatus
    FROM civicrm_option_value
    WHERE option_group_id = (
      SELECT id contributionStatusID
      FROM civicrm_option_group
      WHERE name = \"contribution_status\"
      LIMIT 1
    ) AND name = 'Completed'
    LIMIT 1
  ) AND co.is_test = 0 
  AND co.invoice_number IS NULL 
  $where
  ORDER BY co.id DESC
  $limit";

        $dao = CRM_Core_DAO::executeQuery($sql);
        $log = array();
        $params = array('version' => 3, 'sequential' => 1, 'return' => 'contribution_id');
        $className = get_class($dao);
        $internal = array_keys(get_class_vars($className));
        while ($dao->fetch()) {
            $entry = get_object_vars($dao);
            // Ghost entry!
            unset($entry['']);
            // Remove internal fields.
            foreach ($internal as $key) {
                unset($entry[$key]);
            }
            // It just disappeared :( $params['invoice_id'] = $entry['invoice_num'];
            $result = civicrm_api('Contribution', 'getsingle', $params);
            if (!empty($result['contribution_id'])) {
                $entry += $result;
                $entry['contributionURL'] = CRM_Utils_System::url('civicrm/contact/view/contribution', 'reset=1&id=' . $entry['contribution_id'] . '&cid=' . $entry['contact_id'] . '&action=view&selectedChild=Contribute');
            }
            if (!empty($result['contact_id'])) {
                $entry['contactURL'] = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=' . $entry['contact_id']);
            }
            $log[] = $entry;
        }
        return $log;
    }

}
