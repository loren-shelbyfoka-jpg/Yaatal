const menuToggle = document.querySelector(".menu-toggle");
if (menuToggle) {
  menuToggle.addEventListener("click", () => {
    const navigation = document.querySelector(".main-nav");
    const isOpen = menuToggle.getAttribute("aria-expanded") === "true";
    menuToggle.setAttribute("aria-expanded", String(!isOpen));
    navigation.classList.toggle("is-open", !isOpen);
  });
  document.querySelectorAll(".main-nav a").forEach(link => link.addEventListener("click", () => {
    document.querySelector(".main-nav").classList.remove("is-open");
    menuToggle.setAttribute("aria-expanded", "false");
  }));
}

const yieldRows = document.querySelector("#yieldRows");
const addYieldRow = document.querySelector("#addYieldRow");
if (yieldRows && addYieldRow) {
  const removeRow = event => {
    const button = event.target.closest(".row-remove");
    if (button) button.closest(".yield-row").remove();
  };
  yieldRows.addEventListener("click", removeRow);
  addYieldRow.addEventListener("click", () => {
    const row = document.createElement("div");
    row.className = "yield-row";
    row.innerHTML = `<input name="yield_crop[]" aria-label="Culture" placeholder="Ex. : Tomate"><input name="yield_year[]" type="number" min="2000" max="${new Date().getFullYear()}" aria-label="Année" placeholder="${new Date().getFullYear() - 1}"><select name="yield_season[]" aria-label="Saison"><option value="">Saison</option><option>Saison fraîche</option><option>Saison chaude</option><option>Hivernage</option></select><input name="yield_amount[]" type="number" min="0.01" step="0.01" aria-label="Rendement en tonnes par hectare" placeholder="t/ha"><button class="row-remove" type="button" aria-label="Supprimer cette saison">×</button>`;
    yieldRows.append(row);
    row.querySelector("input").focus();
  });
}

const orderForm = document.querySelector(".order-form");
const orderTotal = document.querySelector("#liveOrderTotal");
if (orderForm && orderTotal) {
  const refreshOrderTotal = () => {
    let total = 0;
    orderForm.querySelectorAll(".quantity-label input").forEach(input => {
      const quantity = Math.max(0, Number(input.value) || 0);
      const price = Number(input.closest(".product-card").dataset.unitPrice);
      total += quantity * price;
    });
    orderTotal.textContent = `${new Intl.NumberFormat("fr-FR").format(total)} FCFA`;
  };
  orderForm.addEventListener("input", refreshOrderTotal);
  refreshOrderTotal();
}
