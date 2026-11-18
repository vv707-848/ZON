// «طباعة أو حفظ PDF» on a market brief (site/briefs/show): the website runs no inline script.
document.addEventListener("click", (e) => { if (e.target.closest("[data-print]")) window.print(); });
