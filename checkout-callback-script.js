<style>
.product__description span:nth-child(3) { display: none !important; }
.content-box, .step__footer__continue-btn, #loadingMessage { display: none; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    sessionStorage.clear();

    async function getOrderNumber() {
        showLoadingMessage(true); // Show loading message before fetching the order number
        
        try {
            const url = 'https://app.sushi.catering/api/getordernumber/' + Shopify.checkout.order_id;
            const response = await fetch(url);
            const data = await response.json();
            const order_number = data.toString();
            console.log(`The order number is: ${order_number}`);

            const mobileQuery = window.matchMedia("(max-width: 480px)");
            const orderContent = getContent(mobileQuery);
            Shopify.Checkout.OrderStatus.addContentBox(orderContent);

            new QRCode(document.getElementById("order-qr-code"), {
                text: order_number,
                width: 180,
                height: 180,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });

            toggleVisibility(true);
        } catch (error) {
            console.error(error);
            toggleVisibility(false);
        } finally {
            showLoadingMessage(false); // Hide loading message after QR code is generated or in case of error
        }
    }

    function getContent(mql){
        if (mql.matches) {
            return orderContent = '<div style="display: flex; flex-direction: column; align-content: center; justify-content: center; align-items: center;"><div id="order-qr-code" style="flex: 1;margin-bottom: 15px;"></div><div class="custom-message" style="flex: 1; padding-left: 20px;"><p>Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail. Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.</p></div></div>';
        }
        else
            return orderContent = '<div style="display: flex;"><div id="order-qr-code" style="flex: 1;"></div><div class="custom-message" style="flex: 1; padding-left: 20px;"><p>Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail. Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.</p></div></div>';
    }

    function toggleVisibility(show) {
        document.querySelectorAll('.content-box, .step__footer__continue-btn').forEach(el => {
            el.style.display = show ? 'block' : 'none';
        });
    }

    function showLoadingMessage(show) {
        const loadingMessageElement = document.getElementById('loadingMessage') || createLoadingMessage();
        loadingMessageElement.style.display = show ? 'block' : 'none';
    }

    function createLoadingMessage() {
        const loadingMessageElement = document.createElement('div');
        loadingMessageElement.id = 'loadingMessage';
        loadingMessageElement.innerText = 'QR-Code wird geladen...';
        loadingMessageElement.style.position = 'fixed';
        loadingMessageElement.style.top = '55%';
        loadingMessageElement.style.left = '30%';
        loadingMessageElement.style.transform = 'translate(-50%, -50%)';
        loadingMessageElement.style.backgroundColor = '#fff';
        loadingMessageElement.style.padding = '20px';
        loadingMessageElement.style.borderRadius = '5px';
        loadingMessageElement.style.boxShadow = '0 0 10px rgba(0,0,0,0.5)';
        document.body.appendChild(loadingMessageElement);
        return loadingMessageElement;
    }

    getOrderNumber();
});
</script>
