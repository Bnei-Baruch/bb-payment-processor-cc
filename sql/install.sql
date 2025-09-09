-- install sql for BB Services extension, create a table to hold custom codes

CREATE TABLE IF NOT EXISTS civicrm_bb_payment_responses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT        COMMENT 'Custom code Id',
  cid          CHAR(50)                 DEFAULT NULL          COMMENT 'Contact ID from Civi Itself',
  trxn_id      VARCHAR(255)    NOT NULL                       COMMENT 'Transaction Id Returned from Pelecard',
  cardtype     TINYINT                  DEFAULT NULL          COMMENT 'Type of Credit Card',
  cardnum      CHAR(16)                 DEFAULT '*******1234' COMMENT 'Credit Card Last Four Digits',
  cardexp      CHAR(5)                  DEFAULT 'mm/yy'       COMMENT 'Credit Card Expiration Date',
  firstpay     DECIMAL(20, 2)  NOT NULL                       COMMENT 'Amount of First Payment',
  installments INTEGER                  DEFAULT 1             COMMENT 'Number of Installments',
  response     TEXT            NOT NULL                       COMMENT 'Response from Pelecard AS IS',
  amount       DECIMAL(20, 2)           DEFAULT NULL          COMMENT 'Total payment amount',
  token        VARCHAR(255)             DEFAULT NULL          COMMENT 'Token from Pelecard',
  created_at   DATETIME                                       COMMENT 'Date Time of Response',
  updated_at   DATETIME                                       COMMENT 'Date Time of Payment',

  PRIMARY KEY (id),
  KEY (`cid`)
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8
  COLLATE = utf8_unicode_ci
  COMMENT ='Table to store response from Pelecard';
