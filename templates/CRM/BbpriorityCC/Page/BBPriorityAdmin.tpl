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
    </tr>
    {foreach from=$Log item=row}
        <tr>
            <td>{$row.id}</td>
        </tr>
    {/foreach}
</table>
