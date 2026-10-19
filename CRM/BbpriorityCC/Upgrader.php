<?php

/**
 * Collection of upgrade steps
 */
class CRM_BbpriorityCC_Upgrader extends CRM_BbpriorityCC_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  public function getCurrentRevision() {
    // reset the saved extension version as well
    try {
      $xmlfile = CRM_Core_Resources::singleton()->getPath('info.kabbalah.payment.bbpriorityCC','info.xml');
      $myxml = simplexml_load_file($xmlfile);
      $version = (string)$myxml->version;
      CRM_Core_BAO_Setting::setItem($version, 'BB Payments Extension', 'bb_extension_version');
    }
    catch (Exception $e) {
      // ignore
    }
    return parent::getCurrentRevision();
  }
  /**
   * Standard: run an install sql script
   */
  public function install() {
    $this->executeSqlFile('sql/install.sql');
  }

  /**
   * Standard: run an uninstall script
   */
  public function uninstall() {
   $this->executeSqlFile('sql/uninstall.sql');
  }


  /**
   * Example: Run a simple query when a module is enabled
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }
  */

  /**
   * Example: Run a simple query when a module is disabled
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }
  */

  /**
   * Add 'Reference Prefix' (subject) field to BBPCC processor type.
   * Allows distinguishing prod vs stage payments (e.g. 'cv-' vs 'cvs-').
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_1(): bool {
    $this->ctx->log->info('Adding Reference Prefix field to BBPCC processor type');
    $type = civicrm_api3('PaymentProcessorType', 'get', [
      'name'   => 'BBPCC',
      'return' => 'id',
    ]);
    if (!empty($type['id'])) {
      civicrm_api3('PaymentProcessorType', 'create', [
        'id'            => $type['id'],
        'subject_label' => 'Reference Prefix',
      ]);
    }
    return TRUE;
  }

  /**
   * Repurpose url_site as EMV Base URL; update existing processor instances
   * from the old Pelecard logo URL to the checkout.kbb1.com endpoint.
   * Also expose url_site_label in processor type UI.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_2(): bool {
    $this->ctx->log->info('Setting EMV Base URL on BBPCC processor type and instances');

    $type = civicrm_api3('PaymentProcessorType', 'get', [
      'name'   => 'BBPCC',
      'return' => ['id'],
    ]);
    if (!empty($type['id'])) {
      civicrm_api3('PaymentProcessorType', 'create', [
        'id'                  => $type['id'],
        'url_site_label'      => 'EMV Base URL',
        'url_site_default'    => 'https://checkout.kbb1.com',
        'url_site_test_label'   => 'EMV Base URL (Test)',
        'url_site_test_default' => 'https://checkout.kbb1.com',
      ]);

      $processors = civicrm_api3('PaymentProcessor', 'get', [
        'payment_processor_type_id' => $type['id'],
        'return'                    => ['id', 'url_site'],
        'options'                   => ['limit' => 0],
      ]);
      foreach ($processors['values'] as $processor) {
        if (empty($processor['url_site']) || strpos($processor['url_site'], 'checkout.kabbalah.info') !== FALSE) {
          civicrm_api3('PaymentProcessor', 'create', [
            'id'       => $processor['id'],
            'url_site' => 'https://checkout.kbb1.com',
          ]);
        }
      }
    }
    return TRUE;
  }

  /**
   * Example: Run an external SQL script
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
