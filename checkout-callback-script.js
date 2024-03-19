<style>
.product__description span:nth-child(3){display:none !important;}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
sessionStorage.clear();
</script>

<script>
async function getOrderNumber() {
    try {
        const url = 'https://app.sushi.catering/api/getordernumber/' + Shopify.checkout.order_id;
        const response = await fetch(url);
        const data =  await response.json();
        const order_number = data.toString();
        console.log(`The order number is: ${order_number}`);

        var mobileQuery = window.matchMedia("(max-width: 480px)");

        // mobileQuery.addEventListener("change", getContent);
    
        var orderContent = getContent(mobileQuery);
        Shopify.Checkout.OrderStatus.addContentBox(orderContent);
         new QRCode(document.getElementById("order-qr-code"), {
            text: order_number, 
            width: 180,
            height: 180,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
    } catch (error) {
        console.log(error);
    }
}

function getContent(mql){
    if (mql.matches) {
        return orderContent = '<div style="display: flex; flex-direction: column; align-content: center; justify-content: center; align-items: center;"><div id="order-qr-code" style="flex: 1;margin-bottom: 15px;"></div><div class="custom-message" style="flex: 1; padding-left: 20px;"><p>Wenn du keinen QR-Code siehst, lade diese Seite neu. Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail. Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.</p></div></div>';
    }
    else
        return orderContent = '<div style="display: flex;"><div id="order-qr-code" style="flex: 1;"></div><div class="custom-message" style="flex: 1; padding-left: 20px;"><p>Wenn du keinen QR-Code siehst, lade diese Seite neu. Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail. Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.</p></div></div>';
}

getOrderNumber();
</script>