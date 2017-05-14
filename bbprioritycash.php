<?php

require_once 'bbprioritycash.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function bbprioritycash_civicrm_config(&$config) {
  _bbprioritycash_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function bbprioritycash_civicrm_xmlMenu(&$files) {
  _bbprioritycash_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function bbprioritycash_civicrm_install() {
	$params = array(
		'version' => 1,
		'name' => 'BB-Priority CASH Payment Processor',
		'title' => 'BB-Priority CASH Payment Processor',
		'description' => 'Register Cash Payment in Priority',
		'class_name' => 'Payment_BBPriorityCash',
		'billing_mode' => 'notify',
		'is_recur' => 0,
		'payment_type' => 1,
	);
	$result = civicrm_api('PaymentProcessorType', 'create', $params);
  _bbprioritycash_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function bbprioritycash_civicrm_postInstall() {
  _bbprioritycash_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function bbprioritycash_civicrm_uninstall() {
	$params = array(
    'version' => 3,
    'sequential' => 1,
    'name' => 'Redsys',
  );
  $result = civicrm_api('PaymentProcessorType', 'get', $params);
  if($result["count"] == 1) {
    $params = array(
      'version' => 3,
      'sequential' => 1,
      'id' => $result["id"],
    );
    $result = civicrm_api('PaymentProcessorType', 'delete', $params);
	}

  return _bbprioritycash_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function bbprioritycash_civicrm_enable() {
  _bbprioritycash_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function bbprioritycash_civicrm_disable() {
  _bbprioritycash_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function bbprioritycash_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _bbprioritycash_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function bbprioritycash_civicrm_managed(&$entities) {
  _bbprioritycash_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function bbprioritycash_civicrm_caseTypes(&$caseTypes) {
  _bbprioritycash_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function bbprioritycash_civicrm_angularModules(&$angularModules) {
  _bbprioritycash_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function bbprioritycash_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _bbprioritycash_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function bbprioritycash_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function bbprioritycash_civicrm_navigationMenu(&$menu) {
  _bbprioritycash_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'info.kabbalah.payment.bbprioritycash')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _bbprioritycash_civix_navigationMenu($menu);
} // */
