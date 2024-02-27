<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
sessionStorage.clear();

{/* Shopify.Checkout.OrderStatus.addContentBox(
  `<p>Deinen QR-Code erhältst du per Mail. Bitte scanne deinen QR-Code am Gerät und entnehme deine Bestellung.</p>`
); */}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var newSection = document.createElement('div');
    newSection.className = 'custom-section';
    newSection.style.display = 'flex';
    newSection.style.marginTop = '0px'; // Adjust spacing as needed
    newSection.style.borderTop = '1px solid lightgray'; // Adjust spacing as needed
    newSection.style.paddingTop = '30px'; // Adjust spacing as needed

    var qrCodeContainer = document.createElement('div');
    qrCodeContainer.id = 'order-qr-code';
    qrCodeContainer.style.flex = '1';

    var messageContainer = document.createElement('div');
    messageContainer.className = 'custom-message';
    messageContainer.style.flex = '1';
    messageContainer.style.paddingLeft = '20px'; // Adjust spacing as needed
    messageContainer.innerHTML = '<p>Deinen QR-Code erhältst du per Mail. Bitte scanne deinen QR-Code am Gerät und entnehme deine Bestellung.</p>'; // Customize your message

    newSection.appendChild(qrCodeContainer);
    newSection.appendChild(messageContainer);

    var orderSummary = document.querySelector('.order-summary__sections');
    if (orderSummary) {
        orderSummary.appendChild(newSection);

        var order_number = '{{ order.name }}';
        order_number = order_number.replace('#', '');
        new QRCode(document.getElementById("order-qr-code"), {
            text: order_number,
            width: 170,
            height: 170,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
    }
});
</script>

