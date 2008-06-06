<?php
require_once('../includes/prepend.inc.php');

// Check that the user is properly authenticated
if (!isset($_SESSION['intUserAccountId'])) {
    // authenticate error
	QApplication::Redirect('./index.php');
}
else QApplication::$objUserAccount = UserAccount::Load($_SESSION['intUserAccountId']);

$strWarning = "";
$arrCheckedAssetCode = "";

if ($_POST && $_POST['method'] == 'complete_transaction') {
	/*
	Run error checking on the array of asset codes and the destination location
	If there are no errors, then you will add the transaction to the database.
		That will include an entry in the Transaction and Asset Transaction table.
		You will also have to change the asset.location_id to the destination location
	*/
	$arrAssetCode = explode('#',$_POST['result']);
	$blnError = false;
	$arrCheckedAssetCode = array();
	foreach ($arrAssetCode as $strAssetCode) {
		if ($strAssetCode) {
			// Begin error checking
			$objNewAsset = Asset::LoadByAssetCode($strAssetCode);
			if (!($objNewAsset instanceof Asset)) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset code does not exist.<br />";
			}				
			// Cannot move, check out/in, nor reserve/unreserve any assets that have been shipped
			elseif ($objNewAsset->LocationId == 2) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset has already been shipped.<br />";
			}
			// Cannot move, check out/in, nor reserve/unreserve any assets that are scheduled to  be received
			elseif ($objNewAsset->LocationId == 5) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset is currently scheduled to be received.<br />";
			}
			elseif ($objPendingShipment = AssetTransaction::PendingShipment($objNewAsset->AssetId)) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset is already in a pending shipment.<br />";
			}
			// Move
			elseif ($objNewAsset->CheckedOutFlag) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset is checked out.<br />";
			}
			elseif ($objNewAsset->ReservedFlag) {
				$blnError = true;
				$strWarning .= $strAssetCode." - That asset is reserved.<br />";
			}
			else {
			    $arrCheckedAssetCode[] = $strAssetCode;
			}
			
			if (!$blnError && $objNewAsset instanceof Asset)  {
				$objAssetArray[] = $objNewAsset;
			}
		}
		else {
			$strWarning .= "Please enter an asset code.<br />";
		}
	}
	
	if (!$blnError) {
        $objDestinationLocation = Location::LoadByShortDescription($_POST['destination_location']);
        if (!$objDestinationLocation) {
            $blnError = true;
            $strWarning .= $_POST['destination_location']." - Destination Location does not exist.<br />";
        }
        else {
    	    $intDestinationLocationId = $objDestinationLocation->LocationId;
    	    
    	    // HJ Change
    	    // I moved these outside of the foreach, because this should only be 1 transaction.
    	    // There is a 1 to Many relationship between Transaction and AssetTransaction so each Transaction can have many AssetTransactions.
    	    $objTransaction = new Transaction();
    		$objTransaction->EntityQtypeId = EntityQtype::Asset;
    		$objTransaction->TransactionTypeId = 1; // Move
    		$objTransaction->Save();
    	    
    	    foreach ($objAssetArray as $objAsset) {
      			$objAssetTransaction = new AssetTransaction();
      			$objAssetTransaction->AssetId = $objAsset->AssetId;
      			$objAssetTransaction->TransactionId = $objTransaction->TransactionId;
      			$objAssetTransaction->SourceLocationId = $objAsset->LocationId;
      			$objAssetTransaction->DestinationLocationId = $intDestinationLocationId;
      			$objAssetTransaction->Save();
      			
      			$objAsset->LocationId = $intDestinationLocationId;
      			$objAsset->Save();
      		}
    		$strWarning .= "Your transaction has successfully completed<br /><a href='index.php'>Main Menu</a> | <a href='asset_menu.php'>Manage Assets</a><br />";
    		//Remove that flag when transaction is compelete or exists some errors
            unset($_SESSION['intUserAccountId']);
            $arrCheckedAssetCode = "";
        }        
	}
	else {
	    $strWarning .= "This transaction has not been completed.<br />";
	}
	if (is_array($arrCheckedAssetCode)) {
	    $strJavaScriptCode = "";
	    foreach ($arrCheckedAssetCode as $strAssetCode) {
	    	$strJavaScriptCode .= "AddAssetPost('".$strAssetCode."');";
	    }
	}
}

?>

<html>
<head>
<title>Tracmor Portable Interface - Move Assets</title>
<link rel="stylesheet" type="text/css" href="/css/portable.css">
<script>
var arrayAssetCode = new Array();
var i=0;
function AddAsset() {
    strAssetCode = document.getElementById('asset_code').value;
    if (strAssetCode != '') {
        document.getElementById('warning').innerHTML = "";
        arrayAssetCode[i] = strAssetCode;
        document.getElementById('result').innerHTML += arrayAssetCode[i++] + "<br/>";
        document.getElementById('asset_code').value = '';
    }
    else {
        document.getElementById('warning').innerHTML = "Asset Code cannot be empty";
    }
    document.getElementById('asset_code').focus();
}
function AddAssetPost(strAssetCode) {
    arrayAssetCode[i] = strAssetCode;
    document.getElementById('result').innerHTML += arrayAssetCode[i++] + "<br/>";
}

function CompleteMove() {
    var strAssetCode = "";
    var strAssetCode = arrayAssetCode.join("#");
    if (arrayAssetCode.length == 0) {
        document.getElementById('warning').innerHTML = "You must provide at least one asset";
        return false;
    }
    if (document.main_form.destination_location.value == "") {
        document.getElementById('warning').innerHTML = "Destination Location cannot be empty";
        return false;
    }
    if (arrayAssetCode.length>0 && document.main_form.destination_location.value != "") {
         document.main_form.result.value = strAssetCode;
         document.main_form.submit();
    }
}
</script>
</head>
<body onload="document.getElementById('asset_code').value=''; document.getElementById('asset_code').focus(); <?php if (is_array($arrCheckedAssetCode)) echo $strJavaScriptCode; ?>">

<h1>TRACMOR PORTABLE INTERFACE</h1>
<h3>Move Assets</h3>
<div id="warning"><?php echo $strWarning; ?></div>
Asset Code: <input type="text" id="asset_code" onkeypress="javascript:if(event.keyCode=='13') AddAsset();" size="10">
<input type="button" value="Add Asset" onclick="javascript:AddAsset();">
<br /><br />
<form method="post" name="main_form" onsubmit="javascript:CompleteMove();">
<input type="hidden" name="method" value="complete_transaction">
<input type="hidden" name="result" value="">
Destination Location: <input type="text" name="destination_location" size ="20">
<input type="button" value="Complete Move" onclick="javascript:CompleteMove();">
</form>
<div id="result"></div>

</body>
</html>