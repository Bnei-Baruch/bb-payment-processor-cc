{if $unhandledContributions > 0}
    <h3 class="error" style="border: 1px solid red;">{ts}Warning!{/ts} There are {$unhandledContributions} unhandled contributions</h3>
{/if}


<h3>Recent transactions</h3>
<form method="GET">
    <fieldset>
        <legend>Filter By</legend>
        <table>
            <tr>
                <td>
                    <label for="search_id">Contribution ID: </label>
                    <input size="4" type="text" id="search_id" name="search_id" value="{$search.id}"></td>
                <td><input type="submit" value="Filter" class='crm-button'></td>
            </tr>
        </table>
    </fieldset>
</form>

<table class="bbpriority-report">
    <caption>Recent transactions</caption>
    <tr>
        <th>{ts}ID{/ts}</th>
        <th>{ts}Org{/ts}</th>
        <th>{ts}SKU{/ts}</th>
        <th>{ts}46{/ts}</th>
        <th>{ts}Max Installments{/ts}</th>
        <th>{ts}User{/ts}</th>
        <th>{ts}Participants{/ts}</th>
        <th>{ts}Description{/ts}</th>
        <th>{ts}Card Type{/ts}</th>
        <th>{ts}Card Num{/ts}</th>
        <th>{ts}Expiration{/ts}</th>
        <th>{ts}Amount{/ts}</th>
        <th>{ts}Currency{/ts}</th>
        <th>{ts}Installments{/ts}</th>
        <th>{ts}First payment{/ts}</th>
        <th>{ts}Email{/ts}</th>
        <th>{ts}Address{/ts}</th>
        <th>{ts}City{/ts}</th>
        <th>{ts}Cellular{/ts}</th>
        <th>{ts}Country{/ts}</th>
        <th>{ts}Language{/ts}</th>
        <th>{ts}Updated at{/ts}</th>
    </tr>
    {foreach from=$BBPriorityLog item=row}
        <tr>
            <td>{$row.ID}</td>
            <td>{$row.ORG}</td>
            <td>{$row.QAMO_PARTNAME}</td>
            <td>{$row.QAMO_VAT}</td>
            <td>{$row.installments}</td>
            <td>{$row.QAMO_CUSTDES}</td>
            <td>{$row.QAMO_DETAILS}</td>
            <td>{$row.QAMO_PARTDES}</td>
            <td>{$row.QAMO_PAYMENTCODE}</td>
            <td>{$row.QAMO_CARDNUM}</td>
            <td>{$row.QAMO_PAYMENTCOUNT}</td>
            <td>{$row.QAMO_VALIDMONTH}</td>
            <td>{$row.QAMO_PAYPRICE}</td>
            <td>{$row.QAMO_CURRNCY}</td>
            <td>{$row.QAMO_PAYCODE}</td>
            <td>{$row.QAMO_FIRSTPAY}</td>
            <td>{$row.QAMO_EMAIL}</td>
            <td>{$row.QAMO_ADRESS}</td>
            <td>{$row.QAMO_CITY}</td>
            <td>{$row.QAMO_CELL}</td>
            <td>{$row.QAMO_FROM}</td>
            <td>{$row.QAMO_LANGUAGE}</td>
            <td>{$row.QAMM_UDATE}</td>
        </tr>
    {/foreach}
</table>
