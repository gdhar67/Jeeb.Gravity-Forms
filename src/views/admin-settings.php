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

		<tr valign="top">
      <th>Live/Test Environment</th>
			<td>
				<select name="jeebNetwork" class="jeebNetwork">
					<option value="Livenet">Livenet</option>
					<option value="Testnet">Testnet</option>
				</select>
				<p><font size='2'>For debugging purposes please use Testnet.</font></p>
			</td>
		</tr>

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
