#!/usr/bin/php
<?php

/*

Copyright:: 2013, Sebastian Grewe

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

 */

// Change to working directory
chdir(dirname(__FILE__));

// Include all settings and classes
require_once('shared.inc.php');

if ($setting->getValue('disable_payouts') == 1) {
  $log->logInfo(" payouts disabled via admin panel");
  $monitoring->endCronjob($cron_name, 'E0009', 0, true, false);
}
$log->logInfo("Starting Payout mm...");
if ($bitcoin_mm->can_connect() !== true) {
  $log->logFatal(" unable to connect to merge mining RPC server, exiting");
  $monitoring->endCronjob($cron_name, 'E0006', 1, true);
}
if (!$dWalletBalance = $bitcoin_mm->getbalance())
  $dWalletBalance = 0;

// Fetch our manual payouts, process them
if ($setting->getValue('disable_manual_payouts') != 1 && $aManualPayouts = $transaction_mm->getMPQueue()) {
  // Calculate our sum first
  $dMPTotalAmount = 0;
  foreach ($aManualPayouts as $aUserData) $dMPTotalAmount += $aUserData['confirmed'];
  if ($dMPTotalAmount > $dWalletBalance) {
    $log->logError("  Wallet does not cover MP payouts [MM]");
    $monitoring->endCronjob($cron_name, 'E0079', 0, true);
  }

  $log->logInfo('  found ' . count($aManualPayouts) . ' queued manual payouts');
  $mask = '    | %-10.10s | %-25.25s | %-20.20s | %-40.40s | %-20.20s |';
  $log->logInfo(sprintf($mask, 'UserID', 'Username', 'Balance', 'Address', 'Payout ID'));
  foreach ($aManualPayouts as $aUserData) {
    $transaction_id = NULL;
    $rpc_txid = NULL;
    $log->logInfo(sprintf($mask, $aUserData['id'], $aUserData['username'], $aUserData['confirmed'], $aUserData['coin_address_mm'], $aUserData['payout_id']));
    if (!$oPayout->setProcessed_mm($aUserData['payout_id'])) {
      $log->logFatal('    unable to mark transactions [MM] ' . $aData['id'] . ' as processed. ERROR: ' . $oPayout->getCronError());
      $monitoring->endCronjob($cron_name, 'E0010', 1, true);
    }
    if ($bitcoin_mm->validateaddress($aUserData['coin_address_mm'])) {
      if (!$transaction_id = $transaction_mm->createDebitAPRecord($aUserData['id'], $aUserData['coin_address_mm'], $aUserData['confirmed'] - $config['txfee_manual'])) {
        $log->logFatal('    failed to fullt debit user [MM] ' . $aUserData['username'] . ': ' . $transaction_mm->getCronError());
        $monitoring->endCronjob($cron_name, 'E0064', 1, true);
      } else {
        // Run the payouts from RPC now that the user is fully debited
        try {
          $rpc_txid = $bitcoin_mm->sendtoaddress($aUserData['coin_address_mm'], $aUserData['confirmed'] - $config['txfee_manual']);
        } catch (Exception $e) {
          $log->logError('E0078: RPC method did not return 200 OK [MM]: Address: ' . $aUserData['coin_address_mm'] . ' ERROR: ' . $e->getMessage());
          // Remove this line below if RPC calls are failing but transactions are still added to it
          // Don't blame MPOS if you run into issues after commenting this out!
          $monitoring->endCronjob($cron_name, 'E0078', 1, true);
        }
        // Update our transaction and add the RPC Transaction ID
        if (empty($rpc_txid) || !$transaction_mm->setRPCTxId($transaction_id, $rpc_txid))
          $log->logError('Unable to add RPC transaction ID [MM] ' . $rpc_txid . ' to transaction record ' . $transaction_id . ': ' . $transaction_mm->getCronError());
      }
    } else {
      $log->logInfo('    failed to validate address for user [MM]: ' . $aUserData['username']);
      continue;
    }
  }
}

if (!$dWalletBalance = $bitcoin_mm->getbalance())
  $dWalletBalance = 0;
// Fetch our auto payouts, process them
if ($setting->getValue('disable_auto_payouts') != 1 && $aAutoPayouts = $transaction_mm->getAPQueue()) {
  // Calculate our sum first
  $dAPTotalAmount = 0;
  foreach ($aAutoPayouts as $aUserData) $dAPTotalAmount += $aUserData['confirmed'];
  if ($dAPTotalAmount > $dWalletBalance) {
    $log->logError(" Wallet does not cover AP payouts [MM]");
    $monitoring->endCronjob($cron_name, 'E0079', 0, true);
  }

  $log->logInfo('  found ' . count($aAutoPayouts) . ' queued auto payouts');
  $mask = '    | %-10.10s | %-25.25s | %-20.20s | %-40.40s | %-20.20s |';
  $log->logInfo(sprintf($mask, 'UserID', 'Username', 'Balance', 'Address', 'Threshold'));
  foreach ($aAutoPayouts as $aUserData) {
    $transaction_id = NULL;
    $rpc_txid = NULL;
    $log->logInfo(sprintf($mask, $aUserData['id'], $aUserData['username'], $aUserData['confirmed'], $aUserData['coin_address_mm'], $aUserData['ap_threshold_mm']));
    if ($bitcoin_mm->validateaddress($aUserData['coin_address_mm'])) {
      if (!$transaction_id = $transaction_mm->createDebitAPRecord($aUserData['id'], $aUserData['coin_address_mm'], $aUserData['confirmed'] - $config['txfee_manual'])) {
        $log->logFatal('    failed to fully debit user [MM] ' . $aUserData['username'] . ': ' . $transaction_mm->getCronError());
        $monitoring->endCronjob($cron_name, 'E0064', 1, true);
      } else {
        // Run the payouts from RPC now that the user is fully debited
        try {
          $rpc_txid = $bitcoin_mm->sendtoaddress($aUserData['coin_address_mm'], $aUserData['confirmed'] - $config['txfee_manual']);
        } catch (Exception $e) {
          $log->logError('E0078: RPC method did not return 200 OK [MM]: Address: ' . $aUserData['coin_address_mm'] . ' ERROR: ' . $e->getMessage());
          // Remove this line below if RPC calls are failing but transactions are still added to it
          // Don't blame MPOS if you run into issues after commenting this out!
          $monitoring->endCronjob($cron_name, 'E0078', 1, true);
        }
        // Update our transaction and add the RPC Transaction ID
        if (empty($rpc_txid) || !$transaction_mm->setRPCTxId($transaction_id, $rpc_txid))
          $log->logError('Unable to add RPC transaction ID [MM] ' . $rpc_txid . ' to transaction record ' . $transaction_id . ': ' . $transaction_mm->getCronError());
      }
    } else {
      $log->logInfo('    failed to validate address for user [MM]: ' . $aUserData['username']);
      continue;
    }
  }
}

require_once('cron_end.inc.php');
