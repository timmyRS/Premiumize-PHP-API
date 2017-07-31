<?php
require "Premiumize.class.php";

$acc = Premiumize::accountInfo(); // Info about our Account
var_dump($acc);

if($acc["premium_until"]) // Check if our Account has Premium. "premium_until" is FALSE if not.
{
	var_dump(
		Premiumize::getRootFolder()->getContent() // Content of the root Folder
		);

	$transfers = Premiumize::getTransfers(); // All Transfers

	foreach($transfers as $transfer) // Foreaching through all Transfers
	{
		if($transfer->status == PremiumizeTransferStatus::FINISHED) // If finished
		{
			Premiumize::clearFinishedTransfers(); // Clear all finished Transfers
			$transfers = Premiumize::getTransfers(); // Get Transfers but this time without finished ones
			break;
		}
	}

	foreach($transfers as $transfer) // Foreaching through all Transfers
	{
		if($transfer->status == PremiumizeTransferStatus::WAITING) // If Downloading
		{
			echo "Downloading {$transfer->name} at {$transfer->down_speed}.\nProgress: ".($transfer->progress * 100)."%\n\n"; // Display Stats
		}
	}
}
?>