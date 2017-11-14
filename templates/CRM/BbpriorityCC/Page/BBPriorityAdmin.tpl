{if $unhandledContributions > 0}
    <h3 class="error">{ts}Warning!{/ts} There are {$unhandledContributions} unhandled contributions</h3>
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
        <th>{ts}Invoice{/ts}</th>
        <th>{ts}Contact{/ts}</th>
        <th>{ts}Request IP{/ts}</th>
        <th>{ts}Card Number{/ts}</th>
        <th>{ts}Total{/ts}</th>
        <th>{ts}Request DateTime{/ts}</th>
        <th>{ts}Result{/ts}</th>
        <th>{ts}Transaction ID{/ts}</th>
        <th>{ts}Response DateTime{/ts}</th>
    </tr>
    {foreach from=$Log item=row}
        <tr>
            {if $row.contributionURL != ''}
                <td><a href="{$row.contributionURL}">{$row.invoice_num}</a></td>
            {else}
                <td>{$row.invoice_num}</td>
            {/if}
            {if $row.contactURL != ''}
                <td><a href="{$row.contactURL}">{$row.sort_name}</a></td>
            {else}
                <td></td>
            {/if}
            <td>{$row.ip}</td>
            <td>{$row.cc}</td>
            <td>{$row.total}</td>
            <td>{$row.request_datetime}</td>
            <td>{$row.auth_result}</td>
            <td>{$row.remote_id}</td>
            <td>{$row.response_datetime}</td>
        </tr>
    {/foreach}
</table>
