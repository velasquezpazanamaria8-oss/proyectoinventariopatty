/**
 * Lienzo de la cotización.
 *
 * La hoja se dibuja a escala 1: un punto del PDF es un píxel en pantalla, así
 * que lo que se coloca aquí cae en el mismo sitio en el documento. Las
 * coordenadas se cuentan desde arriba, como se mira una hoja; el generador de
 * PDF hace la resta.
 *
 * Lo que se ve en cada bloque es una aproximación en HTML —para poder
 * arrastrar hace falta HTML—, pero el juez es la vista previa de la derecha,
 * que es el PDF de verdad. Por eso los bloques compuestos (cliente, totales,
 * firmas) se dibujan sólo lo justo para saber dónde están y cuánto ocupan.
 */
(function () {
  'use strict';

  var S = window.LIENZO;
  if (!S) return;

  var ANCHO_HOJA = 595.28;
  var MARGEN = 30;
  var ALTO_PIE = 320;          // lo que se enseña de la zona de pie
  var REJILLA = 5;             // el arrastre encaja en puntos de 5 en 5

  var bloques = S.bloques.slice();
  var altoCabecera = S.altoCabecera;
  var sel = -1;                // índice del bloque seleccionado

  var zonas = {
    cabecera: document.getElementById('zonaCabecera'),
    pie: document.getElementById('zonaPie')
  };
  var hoja = document.getElementById('hoja');
  var props = document.getElementById('props');
  var divisor = document.getElementById('divisor');
  var marco = document.getElementById('marcoPrevia');
  var estadoPrevia = document.getElementById('previaEstado');

  // ---------------------------------------------------------------- dibujo

  function texto(b) {
    if (b.tipo === 'dato') return S.valores[b.clave] || '(' + b.clave + ')';
    if (b.tipo === 'texto') return b.texto || '';
    return '';
  }

  function nombre(b) {
    switch (b.tipo) {
      case 'dato': return S.valores[b.clave] ? b.clave : b.clave + ' (vacío)';
      case 'texto': return 'texto fijo';
      case 'logo': return 'logo';
      case 'caja': return 'recuadro';
      case 'linea': return 'línea';
      case 'cliente': return 'ficha del cliente';
      case 'totales': return 'totales';
      case 'firmas': return 'firmas';
      case 'parrafo': return b.clave === 'notas' ? 'notas' : 'condiciones';
    }
    return b.tipo;
  }

  function cuerpo(b) {
    var d = document.createElement('div');
    d.className = 'bl-cuerpo bl-' + b.tipo;

    if (b.tipo === 'dato' || b.tipo === 'texto') {
      d.textContent = texto(b);
      d.style.fontSize = b.tam + 'px';
      d.style.lineHeight = (b.tam * 1.2) + 'px';
      d.style.fontWeight = b.negrita ? '700' : '400';
      d.style.color = b.color;
      d.style.textAlign = b.alin === 'der' ? 'right' : (b.alin === 'centro' ? 'center' : 'left');
    } else if (b.tipo === 'caja') {
      d.style.background = b.color;
      d.style.height = b.h + 'px';
    } else if (b.tipo === 'linea') {
      d.style.borderTop = '1px solid ' + b.color;
    } else if (b.tipo === 'logo') {
      d.style.height = b.h + 'px';
      if (S.logo) {
        var img = document.createElement('img');
        img.src = S.logo;
        d.appendChild(img);
      } else {
        d.textContent = 'sin logo cargado';
      }
    } else if (b.tipo === 'cliente') {
      d.style.height = '62px';
      d.innerHTML = '<span class="bl-rotulo" style="color:' + b.color + '">CLIENTE</span>'
        + '<span class="bl-linea">Empresa: ' + (S.valores['cliente.nombre'] || '') + '</span>'
        + '<span class="bl-linea">Dirección: ' + (S.valores['cliente.direccion'] || '') + '</span>'
        + '<span class="bl-linea">RUC · E-mail</span>';
    } else if (b.tipo === 'totales') {
      d.innerHTML = '<span class="bl-linea">SUBTOTAL</span><span class="bl-linea">IGV (18%)</span>'
        + '<span class="bl-fuerte" style="background:' + b.color + '">TOTAL</span>';
    } else if (b.tipo === 'firmas') {
      d.innerHTML = '<span class="bl-firma">' + S.firmaIzq + '</span>'
        + '<span class="bl-firma der">' + S.firmaDer + '</span>';
    } else if (b.tipo === 'parrafo') {
      var t = (b.clave === 'notas' ? S.notas : S.condiciones) || '(sin texto: se escribe en las opciones)';
      d.style.fontSize = b.tam + 'px';
      d.style.color = b.color;
      d.textContent = (b.clave === 'condiciones' ? 'TÉRMINOS Y CONDICIONES\n' : '') + t;
    }
    return d;
  }

  function pintar() {
    Object.keys(zonas).forEach(function (z) {
      zonas[z].querySelectorAll('.bloque').forEach(function (n) { n.remove(); });
    });
    zonas.cabecera.style.height = altoCabecera + 'px';
    zonas.pie.style.height = ALTO_PIE + 'px';

    bloques.forEach(function (b, i) {
      var zona = zonas[b.zona] || zonas.cabecera;
      var el = document.createElement('div');
      el.className = 'bloque' + (i === sel ? ' sel' : '');
      el.style.left = b.x + 'px';
      el.style.top = b.y + 'px';
      el.style.width = b.w + 'px';
      el.dataset.i = i;
      el.title = nombre(b);
      el.appendChild(cuerpo(b));

      var tirador = document.createElement('span');
      tirador.className = 'tirador';
      tirador.dataset.tirar = i;
      el.appendChild(tirador);

      zona.appendChild(el);
    });

    document.getElementById('campoBloques').value = JSON.stringify(bloques);
    document.getElementById('campoAlto').value = altoCabecera;
    refrescarProps();
  }

  /**
   * Tras arrastrar, las casillas de posición tienen que seguir al bloque; pero
   * rehacer el panel entero mientras se escribe en una de ellas le quitaría el
   * foco a media cifra. Así que sólo se refresca el valor, y nunca el de la
   * casilla en la que se está escribiendo.
   */
  function refrescarProps() {
    var b = bloques[sel];
    if (!b) return;
    Object.keys(propsCampos).forEach(function (k) {
      var i = propsCampos[k];
      if (i && i !== document.activeElement) i.value = b[k];
    });
  }

  /** Cambiar de bloque seleccionado sí rehace el panel: es otro bloque. */
  function seleccionar(i) {
    sel = i;
    pintar();
    verProps();
  }

  // ----------------------------------------------------------- propiedades

  function campo(etiqueta, control) {
    var c = document.createElement('div');
    c.className = 'campo';
    c.innerHTML = '<label>' + etiqueta + '</label>';
    c.appendChild(control);
    return c;
  }

  function entrada(tipo, valor, alCambiar, extra) {
    var i = document.createElement('input');
    i.type = tipo;
    i.value = valor;
    Object.keys(extra || {}).forEach(function (k) { i.setAttribute(k, extra[k]); });
    i.addEventListener('input', function () { alCambiar(i.value); cambio(); });
    return i;
  }

  var propsCampos = {};

  function verProps() {
    props.innerHTML = '';
    propsCampos = {};
    var b = bloques[sel];
    if (!b) {
      props.innerHTML = '<p class="lienzo-nota">Toque un bloque de la hoja para cambiarlo.</p>';
      return;
    }

    var titulo = document.createElement('p');
    titulo.className = 'lienzo-titulo-bloque';
    titulo.textContent = nombre(b);
    props.appendChild(titulo);

    if (b.tipo === 'texto') {
      props.appendChild(campo('Texto', entrada('text', b.texto || '',
        function (v) { b.texto = v; }, { maxlength: 200 })));
    }

    var rejilla = document.createElement('div');
    rejilla.className = 'props-rejilla';
    propsCampos.x = entrada('number', b.x, function (v) { b.x = +v; });
    propsCampos.y = entrada('number', b.y, function (v) { b.y = +v; });
    propsCampos.w = entrada('number', b.w, function (v) { b.w = +v; });
    rejilla.appendChild(campo('X', propsCampos.x));
    rejilla.appendChild(campo('Y', propsCampos.y));
    rejilla.appendChild(campo('Ancho', propsCampos.w));
    if (b.tipo === 'caja' || b.tipo === 'logo') {
      propsCampos.h = entrada('number', b.h, function (v) { b.h = +v; });
      rejilla.appendChild(campo('Alto', propsCampos.h));
    }
    props.appendChild(rejilla);

    if (b.tipo === 'dato' || b.tipo === 'texto' || b.tipo === 'parrafo') {
      var r2 = document.createElement('div');
      r2.className = 'props-rejilla';
      r2.appendChild(campo('Tamaño', entrada('number', b.tam,
        function (v) { b.tam = +v; }, { min: 5, max: 40, step: 0.5 })));

      if (b.tipo !== 'parrafo') {
        var alin = document.createElement('select');
        [['izq', 'Izquierda'], ['centro', 'Centro'], ['der', 'Derecha']].forEach(function (o) {
          var op = document.createElement('option');
          op.value = o[0]; op.textContent = o[1];
          if (b.alin === o[0]) op.selected = true;
          alin.appendChild(op);
        });
        alin.addEventListener('change', function () { b.alin = alin.value; cambio(); });
        r2.appendChild(campo('Alineación', alin));
      }
      props.appendChild(r2);
    }

    props.appendChild(campo('Color', entrada('color', b.color, function (v) { b.color = v.toUpperCase(); })));

    if (b.tipo === 'dato' || b.tipo === 'texto') {
      var neg = document.createElement('label');
      neg.className = 'lienzo-check';
      var chk = document.createElement('input');
      chk.type = 'checkbox';
      chk.checked = !!b.negrita;
      chk.addEventListener('change', function () { b.negrita = chk.checked ? 1 : 0; cambio(); });
      neg.appendChild(chk);
      neg.appendChild(document.createTextNode(' Negrita'));
      props.appendChild(neg);
    }

    // Cambiar de zona: el mismo bloque sirve arriba y abajo, y moverlo a mano
    // entre zonas es lo que uno espera poder hacer.
    var permitidas = zonasDe(b.tipo);
    if (permitidas.length > 1) {
      var z = document.createElement('select');
      permitidas.forEach(function (v) {
        var op = document.createElement('option');
        op.value = v;
        op.textContent = v === 'cabecera' ? 'Cabecera' : 'Pie';
        if (b.zona === v) op.selected = true;
        z.appendChild(op);
      });
      z.addEventListener('change', function () { b.zona = z.value; b.y = 10; cambio(); });
      props.appendChild(campo('Zona', z));
    }

    var quitar = document.createElement('button');
    quitar.type = 'button';
    quitar.className = 'btn btn-sm btn-rojo';
    quitar.style.marginTop = '12px';
    quitar.textContent = 'Quitar este bloque';
    quitar.addEventListener('click', function () {
      bloques.splice(sel, 1);
      seleccionar(-1);
      cambio();
    });
    props.appendChild(quitar);
  }

  function zonasDe(tipo) {
    if (tipo === 'dato') return ['cabecera', 'pie'];
    if (tipo === 'cliente') return ['cabecera'];
    if (tipo === 'totales' || tipo === 'firmas' || tipo === 'parrafo') return ['pie'];
    return ['cabecera', 'pie'];
  }

  // -------------------------------------------------------------- arrastre

  var arrastre = null;

  hoja.addEventListener('pointerdown', function (ev) {
    var tirador = ev.target.closest('.tirador');
    var el = ev.target.closest('.bloque');
    if (!el) {
      if (!ev.target.closest('.divisor')) { seleccionar(-1); }
      return;
    }
    var i = +el.dataset.i;
    sel = i;
    arrastre = {
      b: bloques[i],
      tirar: !!tirador,
      x0: ev.clientX, y0: ev.clientY,
      bx: bloques[i].x, by: bloques[i].y, bw: bloques[i].w, bh: bloques[i].h
    };
    seleccionar(i);
    ev.preventDefault();
  });

  // El movimiento se escucha en la ventana: al redibujar, el bloque que se
  // arrastraba deja de existir, así que no puede ser él quien reciba el resto
  // del gesto.
  window.addEventListener('pointermove', function (ev) {
    if (!arrastre) return;
    var dx = ev.clientX - arrastre.x0;
    var dy = ev.clientY - arrastre.y0;
    var b = arrastre.b;

    // Con Alt se mueve punto a punto: la rejilla ayuda a alinear, pero a veces
    // hace falta el ajuste fino.
    var encajar = function (v) { return ev.altKey ? Math.round(v) : Math.round(v / REJILLA) * REJILLA; };

    if (arrastre.tirar) {
      b.w = Math.max(8, Math.min(ANCHO_HOJA, encajar(arrastre.bw + dx)));
      if (b.tipo === 'caja' || b.tipo === 'logo') {
        b.h = Math.max(2, encajar(arrastre.bh + dy));
      }
    } else {
      b.x = Math.max(0, Math.min(ANCHO_HOJA - 8, encajar(arrastre.bx + dx)));
      b.y = Math.max(0, encajar(arrastre.by + dy));
    }
    pintar();
  });

  ['pointerup', 'pointercancel'].forEach(function (ev) {
    window.addEventListener(ev, function () {
      if (arrastre) { arrastre = null; cambio(); }
    });
  });

  // Divisor: dónde termina la cabecera y empieza la tabla.
  divisor.addEventListener('pointerdown', function (ev) {
    var y0 = ev.clientY, alto0 = altoCabecera;
    divisor.setPointerCapture(ev.pointerId);
    function mover(e) {
      altoCabecera = Math.max(80, Math.min(600, Math.round((alto0 + e.clientY - y0) / REJILLA) * REJILLA));
      pintar();
    }
    function soltar() {
      divisor.removeEventListener('pointermove', mover);
      divisor.removeEventListener('pointerup', soltar);
      cambio();
    }
    divisor.addEventListener('pointermove', mover);
    divisor.addEventListener('pointerup', soltar);
    ev.preventDefault();
  });

  // ------------------------------------------------------------ añadir

  document.querySelectorAll('[data-nuevo]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tipo = btn.dataset.nuevo;
      var zona = zonasDe(tipo)[0];
      var b = {
        tipo: tipo, zona: zona, clave: btn.dataset.clave || (tipo === 'parrafo' ? 'condiciones' : ''),
        x: MARGEN, y: 10, w: tipo === 'totales' ? 190 : 200, h: tipo === 'logo' ? 58 : 20,
        tam: 9, negrita: 0, alin: 'izq', color: tipo === 'caja' ? S.color : '#1F2A36'
      };
      if (tipo === 'texto') b.texto = 'Texto';
      if (tipo === 'cliente' || tipo === 'totales' || tipo === 'firmas') b.color = S.color;
      if (tipo === 'firmas' || tipo === 'cliente') b.w = ANCHO_HOJA - MARGEN * 2;

      // Cae en un hueco libre y no encima del último: dos bloques superpuestos
      // al añadirlos parecen uno solo y se arrastra el que no es.
      var mismos = bloques.filter(function (o) { return o.zona === zona; });
      b.y = 10 + mismos.length * 4;

      bloques.push(b);
      seleccionar(bloques.length - 1);
      cambio();
    });
  });

  document.addEventListener('keydown', function (ev) {
    if (sel < 0) return;
    if (document.activeElement && /input|select|textarea/i.test(document.activeElement.tagName)) return;
    if (ev.key === 'Delete' || ev.key === 'Backspace') {
      bloques.splice(sel, 1); seleccionar(-1); cambio(); ev.preventDefault();
    }
    var paso = ev.shiftKey ? 10 : 1;
    var mapa = { ArrowLeft: ['x', -paso], ArrowRight: ['x', paso], ArrowUp: ['y', -paso], ArrowDown: ['y', paso] };
    if (mapa[ev.key]) {
      var b = bloques[sel];
      b[mapa[ev.key][0]] = Math.max(0, b[mapa[ev.key][0]] + mapa[ev.key][1]);
      cambio();
      ev.preventDefault();
    }
  });

  // -------------------------------------------------------------- previa

  var espera = null;

  function cambio() {
    pintar();
    clearTimeout(espera);
    estadoPrevia.textContent = 'pendiente…';
    estadoPrevia.className = 'previa-estado trabajando';
    espera = setTimeout(dibujarPrevia, 600);
  }

  function dibujarPrevia() {
    estadoPrevia.textContent = 'dibujando…';
    var f = document.createElement('form');
    f.method = 'post';
    f.action = S.previa + '#zoom=page-width&toolbar=0&navpanes=0';
    f.target = 'marcoPrevia';
    f.style.display = 'none';

    var token = document.querySelector('#formGuardar input[name="_csrf"]');
    [['_csrf', token ? token.value : ''], ['modo', 'LIBRE'],
     ['alto_cabecera', altoCabecera], ['bloques', JSON.stringify(bloques)]
    ].forEach(function (par) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = par[0]; i.value = par[1];
      f.appendChild(i);
    });

    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
  }

  marco.addEventListener('load', function () {
    estadoPrevia.textContent = 'al día';
    estadoPrevia.className = 'previa-estado';
  });

  pintar();
  verProps();
  dibujarPrevia();
})();
