const moneyFormatter = new Intl.NumberFormat('fr-MG', {
    style: 'currency',
    currency: 'MGA',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

export function formatMoney(value) {
    return moneyFormatter.format(Number(value ?? 0));
}
