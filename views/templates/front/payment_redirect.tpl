{**
 * Auto submit form that sends the buyer to PayKeeper
 *}

<form id="paykeeper-payment-form" action="{$paykeeperAction|escape:'htmlall':'UTF-8'}" method="post" accept-charset="utf-8">
  {foreach from=$paykeeperFields key=field item=value}
    <input type="hidden" name="{$field|escape:'htmlall':'UTF-8'}" value="{$value}" />
  {/foreach}
  <noscript>
    <p>{l s='Click the button to continue to the bank payment page.' mod='paykeeper'}</p>
    <button type="submit" class="btn btn-primary">
      {l s='Pay with PayKeeper' mod='paykeeper'}
    </button>
  </noscript>
</form>

<script>
  document.getElementById('paykeeper-payment-form').submit();
</script>
