// Ortak yardımcılar

// data: ilk satırı başlık olan dizi dizisi (sayılar ham sayı olarak gelir)
function excelAktar(data, dosyaAdi, sayfaAdi) {
  const ws = XLSX.utils.aoa_to_sheet(data);
  ws['!cols'] = data[0].map((baslik, i) => {
    let genislik = String(baslik).length;
    for (const satir of data) {
      const hucre = satir[i];
      if (hucre != null) genislik = Math.max(genislik, String(hucre).length);
    }
    return { wch: Math.min(genislik + 2, 40) };
  });
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, sayfaAdi || 'Liste');
  XLSX.writeFile(wb, dosyaAdi + '.xlsx');
}

function silOnayla(form, mesaj) {
  if (confirm(mesaj || 'Bu kayıt silinsin mi? Bu işlem geri alınamaz.')) {
    form.submit();
  }
  return false;
}
