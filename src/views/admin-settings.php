<div class="wrap">
<h3>Jeeb Payments</h3>
<p style="text-align: left;">
	This Plugin requires you to set up a Jeeb merchant account.
</p>
<ul>
	<li>Navigate to the Jeeb <a href="https://jeeb.io">Sign-up page.</a></li>
</ul>
<br/>
<form action="<?php echo $this->scriptURL; ?>" method="post" id="jeeb-settings-form">
	<table class="form-table">
		<tr>
			<th>API Token</th>
			<td id='jeeb_api_token'>
				<label><input type="text" name="jeebSignature" value="<?php echo $this->frm->jeebSignature; ?>" /></label>
			</td>
		</tr>
		<?php
		$live = $test = "";
		$this->frm->jeebNetwork == "Livenet" ? $live = "selected" : $live = "";
		$this->frm->jeebNetwork == "Testnet" ? $test = "selected" : $test = "";
		?>
		<tr valign="top">
      <th>Live/Test Environment</th>
			<td>
				<select name="jeebNetwork" class="jeebNetwork">
					<option value="Livenet" <?php echo $live; ?>>Livenet</option>
					<option value="Testnet" <?php echo $test; ?>>Testnet</option>
				</select>
				<p><font size='2'>For debugging purposes please use Testnet.</font></p>
			</td>
		</tr>
		<?php
		$btcb = $eeur = $usd = $irr = "";
		$this->frm->jeebBase == "btc" ? $btcb = "selected" : $btcb = "";
		$this->frm->jeebBase == "eur" ? $eur = "selected" : $eur = "";
		$this->frm->jeebBase == "irr" ? $irr = "selected" : $irr = "";
		$this->frm->jeebBase == "usd" ? $usd = "selected" : $usd = "";
		?>
		<tr valign="top">
      <th>Basecoin</th>
			<td>
				<select name="jeebBase" class="jeebBase">
					<option value="btc" <?php echo $btcb; ?>>BTC</option>
					<option value="eur" <?php echo $eur; ?>>EUR</option>
					<option value="irr" <?php echo $irr; ?>>IRR</option>
					<option value="usd" <?php echo $usd; ?>>USD</option>
				</select>
				<p><font size='2'>Select the base-currency</font></p>
			</td>
		</tr>

		<?php
		$btc = $eth = $xrp = $xmr = $bch = $ltc = $test_btc = "";
		$this->frm->jeebBtc == "btc" ? $btc = "checked" : $btc = "";
		$this->frm->jeebEth == "eth" ? $eth = "checked" : $eth = "";
		$this->frm->jeebXrp == "xrp" ? $xrp = "checked" : $xrp = "";
		$this->frm->jeebXmr == "xmr" ? $xmr = "checked" : $xmr = "";
		$this->frm->jeebBch == "bch" ? $bch = "checked" : $bch = "";
		$this->frm->jeebLtc == "ltc" ? $ltc = "checked" : $ltc = "";
		$this->frm->jeebTestBtc == "test-btc" ? $test_btc = "checked" : $test_btc = "";
		?>

		<tr valign="top">
      <th>Targetcoin</th>
			<td>
				<input type="checkbox" name="jeebBtc" value="btc" <?php echo $btc; ?>/>BTC<br>
				<input type="checkbox" name="jeebEth" value="eth" <?php echo $eth; ?>/>ETH<br>
				<input type="checkbox" name="jeebXrp" value="xrp" <?php echo $xrp; ?>/>XRP<br>
				<input type="checkbox" name="jeebXmr" value="xmr" <?php echo $xmr; ?>/>XMR<br>
				<input type="checkbox" name="jeebBch" value="bch" <?php echo $bch; ?>/>BCH<br>
				<input type="checkbox" name="jeebLtc" value="ltc" <?php echo $ltc; ?>/>LTC<br>
				<input type="checkbox" name="jeebTestBtc" value="test-btc" <?php echo $test_btc; ?>/>TEST-BTC<br>
				<p><font size='2'>Select the target-currency.<br>Hold down the Ctrl (windows) / Command (Mac) button to select multiple options.</font></p>
			</td>
		</tr>
		<?php
		$auto = $en = $fa = "";
		$this->frm->jeebLang == "none" ? $auto = "selected" : $auto = "";
		$this->frm->jeebLang == "en" ? $en = "selected" : $en = "";
		$this->frm->jeebLang == "fa" ? $fa = "selected" : $fa = "";
		?>
		<tr valign="top">
      <th>Language</th>
			<td>
				<select name="jeebLang" class="jeebLang">
					<option value="none" <?php echo $auto; ?>>Auto-Select</option>
					<option value="en" <?php echo $en; ?>>English</option>
					<option value="fa" <?php echo $fa; ?>>Persian</option>
				</select>
				<p><font size='2'>Select the language of the payment page.</font></p>
			</td>
		</tr>

		<tr valign="top">
      <th>Redirect URL</th>
			<td>
				<label><input type="text" name="jeebRedirectURL" value="<?php echo $this->frm->jeebRedirectURL; ?>" /></label>
				<p><font size='2'>Put the URL that you want the buyer to be redirected to after payment. This is usually a "Thanks for your order!" page.</font></p><br><br>
				<p><font size='2'><b>NOTE: <br>1. The minimum price of the product should be of 10000 IRR, not less than that, for both Live and Test environment.
																	 <br>2. If you want your customers to receive all the transaction update then include a email field.</b></font></p>
			</td>
		</tr>

	</table>
	<p class="submit">
	<input type="submit" name="Submit" class="button-primary" value="Save Changes" />
	<?php wp_nonce_field('save', $this->menuPage . '_wpnonce', false); ?>
	</p>
</form>

</div>
