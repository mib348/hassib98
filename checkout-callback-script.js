<style>
.product__description span:nth-child(3) { display: none !important; }
.content-box, .step__footer__continue-btn, #loadingMessage { display: none; }

@media (max-width: 480px) {
    #order-qr-code {
        width: 100%;
        max-width: 100%;
        display: flex;
        justify-content: center;
    }
    #order-qr-code img {
        width: 100%;
        height: auto;
    }
}
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
            const order_number = data.order_number.toString();
            console.log(`The order number is: ${order_number}`);
            const stationFlag = data.no_station;
            console.log(`The no_station is: ${stationFlag}`);

            if (stationFlag === 'Y') { 
                displayStationInstructions(true); // No QR code, show alternate text 
            }
            else{
                const mobileQuery = window.matchMedia("(max-width: 480px)");
                const orderContent = getContent(mobileQuery);
                Shopify.Checkout.OrderStatus.addContentBox(orderContent);
    
                new QRCode(document.getElementById("order-qr-code"), {
                    text: order_number,
                    width: mobileQuery.matches ? 350 : 180, // Adjust width based on screen size
                    height: mobileQuery.matches ? 350 : 180, // Adjust height based on screen size
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
    
                toggleVisibility(true);
            }

        } catch (error) {
            console.error(error);
            toggleVisibility(false);
        } finally {
            showLoadingMessage(false); // Hide loading message after QR code is generated or in case of error
        }
    }

    function getContent(mql) {
        if (mql.matches) {
            return `
                <div style="width:100%;max-width:100%;display: flex; flex-direction: column; align-content: center; justify-content: center; align-items: center;">
                    <div id="order-qr-code" style="flex: 1; margin-bottom: 15px;width:100%;"></div>
                    <div class="custom-message" style="flex: 1;">
                        <p>
                            Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Stelle hierfür die maximale Helligkeit deines Mobilgeräts ein und halte dein Mobilgerät waagerecht, mittig und im Abstand von ca. 10cm vor den Scanner.
                            Bitte achte darauf, dass der QR-Code die volle Breite deines Bildschirms ausfüllen muss. Falls der QR-Code im Browser zu klein angezeigt wird, kannst du hineinzoomen oder den QR-Code aus deiner E-Mail verwenden.
                            Nach dem Scannen, folge den Anweisungen auf dem Monitor. Wenn deine Bestellung nicht gefunden wurde, versuche es nach 30 Sekunden erneut. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail.
                            Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.
                        </p>
                    </div>
                </div>
            `;
        } else {
            return `
                <div style="display: flex;">
                    <div id="order-qr-code" style="flex: 1;"></div>
                    <div class="custom-message" style="flex: 1; padding-left: 20px;">
                        <p>
                            Scanne deinen QR-Code an der Station, um deine Artikel zu entnehmen. Stelle hierfür die maximale Helligkeit deines Mobilgeräts ein und halte dein Mobilgerät waagerecht, mittig und im Abstand von ca. 10cm vor den Scanner.
                            Bitte achte darauf, dass der QR-Code die volle Breite deines Bildschirms ausfüllen muss. Falls der QR-Code im Browser zu klein angezeigt wird, kannst du hineinzoomen oder den QR-Code aus deiner E-Mail verwenden.
                            Nach dem Scannen, folge den Anweisungen auf dem Monitor. Wenn deine Bestellung nicht gefunden wurde, versuche es nach 30 Sekunden erneut. Deinen QR-Code findest du auch in deiner Bestellbestätigungsmail.
                            Fragen und Antworten rund um deine Bestellung findest du <a href="https://sushi.catering/pages/faq">hier</a>.
                        </p>
                    </div>
                </div>
            `;
        }
    }

    function displayStationInstructions(showQrCode) {
        const contentBox = document.createElement('div');
        contentBox.style.display = 'block';
        contentBox.innerHTML = `
            <p>
                test test test. Lieferung bis zur Theke.
            </p>
        `;
        Shopify.Checkout.OrderStatus.addContentBox(contentBox);
        toggleVisibility(showQrCode);
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
