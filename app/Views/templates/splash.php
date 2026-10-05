<?php

/**
 * Splash / layar muat (loading screen) global — "AKSARA".
 *
 * Dipasang dari <head> (lewat templates/style.php tiap role) sehingga SATU
 * berkas ini otomatis berlaku di seluruh halaman. Yang dikeluarkan hanya
 * <link rel=preload>, <style>, dan <script> — semuanya sah berada di <head>;
 * markup splash-nya disuntikkan skrip begitu <body> tersedia.
 *
 * Susunan (sesuai permintaan):
 *   atas  : logo AKSARA (kiri)  |  lambang Kabupaten Pringsewu (kanan)
 *   bawah : logo Diskominfo ("Powered By" sudah menyatu di gambarnya)
 *
 * Catatan desain: latar sengaja TERANG. Dua dari tiga berkas logo yang ada
 * (lambang kabupaten & Diskominfo) berlatar putih / bertulisan biru tua, jadi
 * di atas latar gelap keduanya tidak terbaca. Latar terang juga membuat
 * peralihan ke halaman aplikasi (bg-light) mulus tanpa "kedip" gelap.
 *
 * Tanpa JavaScript splash TIDAK pernah muncul (kelas penanda hanya dipasang
 * oleh skrip), jadi mustahil ada overlay yang nyangkut menutupi halaman.
 */

helper('setting');

/**
 * Pakai berkas dari Pengaturan Aplikasi bila ada & valid, selain itu bawaan.
 * NULL bila dua-duanya tidak ada — logo itu lalu dilewati, supaya splash tidak
 * memamerkan ikon "gambar rusak" kalau satu berkas belum ikut ter-upload.
 */
$axBerkas = static function (string $rel, string $bawaan): ?string {
    foreach ([ltrim(trim($rel), '/'), ltrim($bawaan, '/')] as $p) {
        if ($p !== '' && is_file(FCPATH . $p)) {
            return $p;
        }
    }

    return null;
};

$axAksara  = $axBerkas(setting('app_logo', ''), 'assets/images/LogoTentang.png');
$axKab     = $axBerkas(setting('kab_logo', ''), 'assets/images/logo.png');
$axKominfo = $axBerkas('', 'assets/images/diskominfo.png');

/*
 * `onerror`: kalau satu gambar gagal dimuat (jaringan lambat, berkas belum
 * ikut ter-upload), elemennya dibuang — tanpa ini peramban memamerkan kotak
 * "gambar rusak" berisi teks alt di tengah splash.
 */
$axGambar = static function (?string $rel, string $kelas, string $alt, bool $denganGaris = false): string {
    if ($rel === null) {
        return '';
    }

    // Logo yang gagal dimuat ikut membawa garis pemisahnya, supaya tidak
    // tertinggal satu garis menggantung tanpa logo di sebelahnya.
    $onerror = $denganGaris
        ? "(function(i){var p=i.parentNode,g=p.querySelector('.ax-splash-garis');if(g){p.removeChild(g);}p.removeChild(i);})(this)"
        : "(function(i){if(i.parentNode){i.parentNode.removeChild(i);}})(this)";

    return '<img class="' . $kelas . '" src="' . esc(base_url($rel), 'attr') . '"'
        . ' alt="' . esc($alt, 'attr') . '"'
        . ' onerror="' . $onerror . '">';
};

$axMarkup = '<div class="ax-splash-kotak" role="status" aria-label="Memuat halaman">'
    . '<div class="ax-splash-logo">'
    . $axGambar($axAksara, 'ax-splash-aksara', 'AKSARA e-SAKIP', true)
    . (($axAksara !== null && $axKab !== null) ? '<span class="ax-splash-garis" aria-hidden="true"></span>' : '')
    . $axGambar($axKab, 'ax-splash-kab', 'Lambang Kabupaten Pringsewu', true)
    . '</div>'
    . '<div class="ax-splash-bar" aria-hidden="true"><span></span></div>'
    . '<p class="ax-splash-teks">Memuat&hellip;</p>'
    . '</div>'
    . '<div class="ax-splash-kaki">'
    . $axGambar($axKominfo, '', 'Powered by Diskominfo Kabupaten Pringsewu')
    . '</div>';
?>
<?php foreach ([$axAksara, $axKab, $axKominfo] as $axMuatAwal): ?>
    <?php if ($axMuatAwal !== null): ?>
<link rel="preload" as="image" href="<?= esc(base_url($axMuatAwal), 'attr') ?>" />
    <?php endif; ?>
<?php endforeach; ?>

<style>
  /* ===================== Splash / layar muat AKSARA ===================== */

  /* Cat instan: menutup jeda singkat antara skrip di <head> berjalan dan
     <body> terbentuk, supaya tidak ada kilatan halaman setengah jadi. */
  html.ax-memuat:not(.ax-siap)::before {
    content: '';
    position: fixed;
    inset: 0;
    z-index: 2147482000;
    background: #f4f8f3;
  }

  #ax-splash {
    position: fixed;
    inset: 0;
    z-index: 2147483000;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: radial-gradient(900px 560px at 50% 38%, #ffffff 0%, #f5faf4 52%, #e9f2e7 100%);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity .42s ease, visibility 0s linear .42s;
  }

  /* Muncul SEKETIKA (transition: none) — kalau ikut memudar, halaman di
     baliknya sempat terlihat setengah jadi. Memudarnya hanya saat ditutup:
     kelas dilepas -> aturan dasar di atas yang berlaku lagi. */
  html.ax-memuat #ax-splash {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transition: none;
  }

  /* Semburat hijau lembut (warna merek #00743e / #6eab11). */
  #ax-splash::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(520px 320px at 50% 36%, rgba(110, 171, 17, .16), rgba(110, 171, 17, 0) 70%),
      radial-gradient(760px 420px at 50% 112%, rgba(0, 116, 62, .14), rgba(0, 116, 62, 0) 72%);
    animation: ax-denyut 4.5s ease-in-out infinite;
  }

  .ax-splash-kotak {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: clamp(18px, 3.4vh, 30px);
    animation: ax-naik .5s ease both;
  }

  .ax-splash-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: clamp(16px, 4vw, 34px);
  }

  .ax-splash-aksara {
    height: clamp(108px, 20vw, 188px);
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 10px 24px rgba(0, 60, 32, .14));
  }

  .ax-splash-kab {
    height: clamp(78px, 15vw, 140px);
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 10px 24px rgba(0, 60, 32, .14));
  }

  .ax-splash-garis {
    width: 1px;
    height: clamp(56px, 10vw, 96px);
    background: linear-gradient(180deg, rgba(0, 116, 62, 0), rgba(0, 116, 62, .32), rgba(0, 116, 62, 0));
  }

  /* Bilah progres: terisi 0 -> 100% selama MIN_TAMPIL (durasi disetel skrip
     lewat --ax-durasi). Dipakai bilah terisi, bukan yang bolak-balik, karena
     durasi splash sekarang panjang — bilah berputar belasan kali malah
     terbaca "macet". Kalau halaman belum siap saat bilah penuh, bilah
     berdenyut pelan sampai benar-benar ditutup. */
  .ax-splash-bar {
    position: relative;
    width: clamp(168px, 38vw, 280px);
    height: 4px;
    border-radius: 99px;
    background: rgba(0, 116, 62, .14);
    overflow: hidden;
  }

  .ax-splash-bar > span {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 0;
    border-radius: 99px;
    background: linear-gradient(90deg, #00743e, #6eab11);
    animation:
      ax-isi var(--ax-durasi, 3000ms) cubic-bezier(.22, .72, .3, 1) forwards,
      ax-denyut 1.6s ease-in-out var(--ax-durasi, 3000ms) infinite;
  }

  .ax-splash-teks {
    margin: 0;
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    font-size: .8rem;
    font-weight: 600;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: #4d6a58;
  }

  .ax-splash-kaki {
    position: absolute;
    left: 0;
    right: 0;
    bottom: clamp(22px, 6vh, 56px);
    display: flex;
    justify-content: center;
    animation: ax-naik .6s .12s ease both;
  }

  .ax-splash-kaki img {
    height: clamp(30px, 5.6vw, 44px);
    width: auto;
    object-fit: contain;
    opacity: .92;
  }

  @keyframes ax-naik {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: none; }
  }

  @keyframes ax-isi {
    from { width: 0; }
    to   { width: 100%; }
  }

  @keyframes ax-denyut {
    0%, 100% { opacity: .75; }
    50%      { opacity: 1; }
  }

  @media (prefers-reduced-motion: reduce) {
    #ax-splash { transition-duration: .01s; }
    #ax-splash::before,
    .ax-splash-kotak,
    .ax-splash-kaki { animation: none; }
    .ax-splash-bar > span { animation: ax-isi var(--ax-durasi, 3000ms) linear forwards; }
  }

  /* Jangan pernah ikut tercetak. */
  @media print {
    html.ax-memuat:not(.ax-siap)::before { display: none !important; }
    #ax-splash { display: none !important; }
  }
</style>

<script>
  (function () {
    'use strict';

    var d = document,
        R = d.documentElement,
        MARKUP = <?= json_encode($axMarkup, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        /* ======= ATUR DURASI SPLASH DI SINI (milidetik) =======
           MIN_TAMPIL = berapa lama splash PASTI terlihat, walau halaman sudah
           siap duluan; ini angka yang biasanya mau diubah. BATAS_MUAT = batas
           atas pengaman; lewat ini splash ditutup paksa meski halaman belum
           rampung — jarang kepakai, hanya untuk halaman yang benar-benar berat. */
        MIN_TAMPIL   = 3000,   /* lama minimum splash terlihat               */
        BATAS_MUAT   = 10000,  /* pengaman: muat awal paling lama segini     */
        BATAS_PINDAH = 10000,  /* pengaman: pindah halaman paling lama segini */
        SETELAH_DOM  = 900,    /* jeda setelah DOM siap, bila `load` lambat  */
        TUNDA_PINDAH = 160,    /* navigasi kilat tidak usah dikasih splash   */
        mulai = Date.now(),
        pengamanId = null,
        tundaId = null,
        tutupId = null;

    function pasang() {
      if (d.getElementById('ax-splash')) { return true; }
      if (!d.body) { return false; }
      var w = d.createElement('div');
      w.id = 'ax-splash';
      /* Bilah progres terisi persis selama MIN_TAMPIL. */
      w.style.setProperty('--ax-durasi', MIN_TAMPIL + 'ms');
      w.innerHTML = MARKUP;
      d.body.insertBefore(w, d.body.firstChild);
      R.className += ' ax-siap';
      return true;
    }

    /* Elemen splash dipakai ulang, jadi animasi bilahnya harus dipaksa mulai
       dari nol tiap kali splash ditampilkan lagi (mis. saat pindah halaman). */
    function ulangBilah() {
      var s = d.querySelector('#ax-splash .ax-splash-bar > span');
      if (!s) { return; }
      s.style.animation = 'none';
      void s.offsetWidth; // paksa reflow
      s.style.animation = '';
    }

    function tampilkan() {
      clearTimeout(tutupId);
      clearTimeout(pengamanId);
      pasang();
      ulangBilah();
      mulai = Date.now();
      if (R.className.indexOf('ax-memuat') < 0) { R.className += ' ax-memuat'; }
      pengamanId = setTimeout(sembunyikan, BATAS_PINDAH);
    }

    function sembunyikan() {
      clearTimeout(tundaId);
      clearTimeout(pengamanId);
      clearTimeout(tutupId);
      var sisa = Math.max(0, MIN_TAMPIL - (Date.now() - mulai));
      tutupId = setTimeout(function () {
        R.className = R.className.replace(/\bax-memuat\b/g, '').replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
      }, sisa);
    }

    /* ---------------------- muat awal ---------------------- */
    R.className += ' ax-memuat';
    pengamanId = setTimeout(sembunyikan, BATAS_MUAT);

    if (!pasang()) {
      var tik = function () { if (!pasang()) { requestAnimationFrame(tik); } };
      if (window.requestAnimationFrame) { requestAnimationFrame(tik); }
      d.addEventListener('DOMContentLoaded', pasang);
    }

    /* Tutup pada yang lebih dulu: `window.load`, atau DOMContentLoaded + jeda
       pendek. Menunggu `load` saja bisa lama di halaman bergambar besar,
       padahal tata letaknya sudah rapi begitu DOM + CSS siap. */
    if (d.readyState === 'complete') {
      sembunyikan();
    } else {
      window.addEventListener('load', function () { sembunyikan(); });
      if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { setTimeout(sembunyikan, SETELAH_DOM); });
      } else {
        setTimeout(sembunyikan, SETELAH_DOM);
      }
    }

    /* Kembali lewat tombol Back (bfcache): halaman sudah jadi, tutup segera. */
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) { MIN_TAMPIL = 0; sembunyikan(); }
    });

    /* -------------------- pindah halaman -------------------- */

    /* Tautan cetak/unduh tidak memindahkan halaman -> splash bisa nyangkut. */
    var TANPA_SPLASH = /(^|[\/?&=._-])(cetak|export|unduh|download)([\/?&=._-]|$)|\.(pdf|xlsx|xls|csv|docx|zip)($|\?)/i;

    /* Jeda TUNDA_PINDAH sekalian jadi penyaring: penangan lain (dialog
       konfirmasi hapus di templates/konfirmasi.php, modal Bootstrap, dsb.)
       memasang preventDefault SETELAH penyadap ini — kita menyimak di fase
       capture dan terdaftar lebih dulu. Jadi keputusan "jadi pindah atau
       tidak" baru diambil saat jeda habis, bukan saat klik. */
    function antre(e) {
      clearTimeout(tundaId);
      tundaId = setTimeout(function () {
        if (e.defaultPrevented) { return; }
        tampilkan();
      }, TUNDA_PINDAH);
    }

    d.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
      var a = (e.target && e.target.closest) ? e.target.closest('a') : null;
      if (!a) { return; }
      if (a.hasAttribute('download') || a.hasAttribute('data-tanpa-splash')) { return; }
      /* Pemicu Bootstrap (modal/dropdown/collapse/tab): bukan navigasi. */
      if (a.hasAttribute('data-bs-toggle')) { return; }

      var target = a.getAttribute('target');
      if (target && target !== '_self') { return; }

      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|sms|blob|data):/i.test(href)) { return; }
      if (TANPA_SPLASH.test(href)) { return; }

      var u;
      try { u = new URL(a.href, location.href); } catch (err) { return; }
      if (u.origin !== location.origin) { return; }
      /* Hanya ganti anchor di halaman yang sama -> tidak ada muat ulang. */
      if (u.pathname === location.pathname && u.search === location.search && u.hash !== location.hash) { return; }

      antre(e);
    }, true);

    d.addEventListener('submit', function (e) {
      var f = e.target;
      if (e.defaultPrevented || !f || f.tagName !== 'FORM') { return; }
      if (f.hasAttribute('data-tanpa-splash')) { return; }
      var target = f.getAttribute('target');
      if (target && target !== '_self') { return; }
      if (TANPA_SPLASH.test(f.getAttribute('action') || '')) { return; }
      antre(e);
    }, true);
  })();
</script>
