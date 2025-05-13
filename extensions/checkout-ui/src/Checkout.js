import {
  extension,
  Banner,
  BlockStack,
  Checkbox,
  Text,
} from "@shopify/ui-extensions/checkout";

// 1. Choose an extension target
export default extension("purchase.checkout.block.render", (root, api) => {
  // 2. Check instructions for feature availability, see https://shopify.dev/docs/api/checkout-ui-extensions/apis/cart-instructions for details
  api.instructions.subscribe((instructions) => {
    if (!instructions.attributes.canUpdateAttributes) {
      // For checkouts such as draft order invoices, cart attributes may not be allowed
      // Consider rendering a fallback UI or nothing at all, if the feature is unavailable
      root.replaceChildren(
        root.createComponent(
          Banner,
          { title: "checkout-ui", status: "warning" },
          api.i18n.translate("attributeChangesAreNotSupported")
        )
      );
    } else {
      // 3. Render a UI
      root.replaceChildren(
        root.createComponent(
          BlockStack,
          { border: "dotted", padding: "tight" },
          [
            root.createComponent(
              Banner,
              { title: "checkout-ui" },
              api.i18n.translate("welcome", {
                target: root.createComponent(
                  Text,
                  { emphasis: "italic" },
                  api.extension.target
                ),
              })
            ),
            root.createComponent(
              Checkbox,
              {
                onChange: onCheckboxChange,
              },
              api.i18n.translate("iWouldLikeAFreeGiftWithMyOrder")
            ),
          ]
        )
      );
    }

    async function onCheckboxChange(isChecked) {
      // 4. Call the API to modify checkout
      const result = await api.applyAttributeChange({
        key: "requestedFreeGift",
        type: "updateAttribute",
        value: isChecked ? "yes" : "no",
      });
      console.log("applyAttributeChange result", result);
    }
  });
});