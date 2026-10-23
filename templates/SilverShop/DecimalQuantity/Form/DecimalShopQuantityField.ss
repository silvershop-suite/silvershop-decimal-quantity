<%-- Decimal cart-line quantity widget. A theme can override this include. The data-setquantity-link is the
     dedicated server action; theme JS reads the input and posts `quantitydecimal` to it. --%>
<div class="silvershop-decimal-quantity" data-setquantity-link="$SetDecimalQuantityLink.ATT">
    $Field
    <% if $DecimalUnit %>
        <span class="silvershop-decimal-quantity__unit">$DecimalUnit</span>
    <% end_if %>
</div>
