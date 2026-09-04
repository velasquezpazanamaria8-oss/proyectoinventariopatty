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

  // Piezas que aceptan un fondo de color opcional (ver CotizacionDiseno::CON_FONDO).
  var CON_FONDO = ['dato', 'texto', 'parrafo', 'firmas', 'firma1', 'cliente'];

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
  var btnDeshacer = document.getElementById('btnDeshacer');
  var btnRehacer = document.getElementById('btnRehacer');

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
      case 'firma1': return 'firma';
      case 'parrafo': return b.clave === 'notas' ? 'notas' : 'condiciones';
    }
    return b.tipo;
  }

  /** Los rótulos del bloque, con los de fábrica si aún no tiene. */
  function rot(b) {
    var r = b.textos || {};
    var def = S.rotulos[b.tipo] || {};
    var out = {};
    Object.keys(def).forEach(function (k) {
      out[k] = r[k] !== undefined ? r[k] : def[k][1];
    });
    return out;
  }

  function etiquetar(etiqueta, valor) {
    return (etiqueta ? etiqueta + ': ' : '') + (valor || '');
  }

  function trozo(clase, texto, color) {
    var s = document.createElement('span');
    s.className = clase;
    s.textContent = texto || '';
    if (color) s.style.color = color;
    return s;
  }

  function cuerpo(b) {
    var d = document.createElement('div');
    d.className = 'bl-cuerpo bl-' + b.tipo;
    if (b.fondo && CON_FONDO.indexOf(b.tipo) !== -1) {
      d.style.background = b.fondo;
      d.style.padding = '4px';
    }

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
      if (b.marco) d.style.border = '1px solid ' + (b.colorTexto || '#fff');
      if (b.contenido) {
        d.style.color = b.colorTexto || '#FFFFFF';
        d.style.fontSize = b.tam + 'px';
        d.style.fontWeight = b.negrita ? '700' : '400';
        d.style.textAlign = b.alin === 'der' ? 'right' : (b.alin === 'centro' ? 'center' : 'left');
        d.style.padding = '4px';
        d.textContent = b.contenido;
      }
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
      var rc = rot(b);
      d.style.height = '62px';
      d.appendChild(trozo('bl-rotulo', rc.rotulo, b.color));
      d.appendChild(trozo('bl-linea', etiquetar(rc.empresa, S.cliente.nombre), b.color));
      d.appendChild(trozo('bl-linea', etiquetar(rc.direccion, S.cliente.direccion), b.color));
      d.appendChild(trozo('bl-linea', etiquetar(rc.ruc, S.cliente.ruc)
        + '   ' + etiquetar(rc.telefono, S.cliente.telefono)
        + '   ' + etiquetar(rc.email, S.cliente.email), b.color));
    } else if (b.tipo === 'totales') {
      var rt = rot(b);
      d.appendChild(trozo('bl-linea', rt.subtotal, b.color));
      d.appendChild(trozo('bl-linea', rt.igv, b.color));
      var fuerte = trozo('bl-fuerte', rt.total);
      fuerte.style.background = b.color;
      d.appendChild(fuerte);
    } else if (b.tipo === 'firmas') {
      var rf = rot(b);
      d.appendChild(trozo('bl-firma', rf.izq || S.firmaIzq));
      d.appendChild(trozo('bl-firma der', rf.der || S.firmaDer));
    } else if (b.tipo === 'firma1') {
      var rf1 = rot(b);
      d.style.height = b.h + 'px';
      if (b.imagen) {
        var imgF = document.createElement('img');
        imgF.src = S.verFirma + encodeURIComponent(b.imagen);
        imgF.className = 'bl-firma-img';
        d.appendChild(imgF);
      }
      d.appendChild(trozo('bl-firma', rf1.nombre || '(sin nombre)'));
    } else if (b.tipo === 'parrafo') {
      var t = (b.clave === 'notas' ? S.notas : S.condiciones)
        || '(sin texto: se escribe en las opciones)';
      var titulo = rot(b).titulo;
      d.style.fontSize = b.tam + 'px';
      d.style.color = b.color;
      d.textContent = (titulo ? titulo + String.fromCharCode(10) : '') + t;
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
    document.getElementById('campoCondiciones').value = S.condiciones || '';
    document.getElementById('campoNotas').value = S.notas || '';
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

  function entrada(tipo, valor, alCambiar, extra, clave) {
    var i = document.createElement('input');
    i.type = tipo;
    i.value = valor;
    Object.keys(extra || {}).forEach(function (k) { i.setAttribute(k, extra[k]); });
    // La clave agrupa: escribir «CLIENTE» letra a letra es UN cambio, no ocho,
    // o deshacer devolvería una letra cada vez.
    i.addEventListener('input', function () { alCambiar(i.value); cambio(clave || null); });
    return i;
  }

  /** Fondo de color opcional para "enmarcar" un bloque (ver CON_FONDO). */
  function controlFondo(b) {
    var c = document.createElement('div');
    c.className = 'campo';

    var chk = document.createElement('label');
    chk.className = 'lienzo-check';
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = !!b.fondo;
    chk.appendChild(input);
    chk.appendChild(document.createTextNode(' Enmarcar con fondo de color'));
    c.appendChild(chk);

    var color = entrada('color', b.fondo || '#F1F5F9',
      function (v) { b.fondo = v.toUpperCase(); }, null, agrup('fondo'));
    color.style.display = b.fondo ? '' : 'none';
    color.style.marginTop = '6px';
    c.appendChild(color);

    input.addEventListener('change', function () {
      if (input.checked) {
        b.fondo = color.value.toUpperCase();
        color.style.display = '';
      } else {
        b.fondo = null;
        color.style.display = 'none';
      }
      cambio();
    });

    return c;
  }

  /**
   * Subir (o quitar) la foto de la firma de este bloque.
   *
   * Va aparte del resto del formulario: un archivo no cabe en el JSON de
   * bloques que se manda al guardar, así que se sube al toque por su cuenta y
   * lo que queda en el bloque es sólo la ruta que devuelve el servidor.
   */
  function subirFirma(b) {
    var c = document.createElement('div');
    c.className = 'campo';
    c.innerHTML = '<label>Imagen de la firma (foto o escaneo)</label>';

    var estado = document.createElement('div');
    estado.className = 'lienzo-nota';
    estado.textContent = b.imagen ? 'Ya tiene una imagen subida.' : 'Sin imagen: queda la línea en blanco para firmar a mano.';
    c.appendChild(estado);

    var fila = document.createElement('div');
    fila.style.display = 'flex';
    fila.style.gap = '8px';
    fila.style.marginTop = '4px';

    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.addEventListener('change', function () {
      var archivo = input.files[0];
      if (!archivo) return;

      // Aviso local antes de subir: así no se espera a que el servidor
      // rechace algo que ya se sabe que no va a entrar.
      if (archivo.size > 2 * 1024 * 1024) {
        estado.textContent = 'Esa imagen pasa de 2 MB. Pruebe con una más liviana.';
        input.value = '';
        return;
      }

      estado.textContent = 'Subiendo...';

      var token = document.querySelector('#formGuardar input[name="_csrf"]');
      var datos = new FormData();
      datos.append('_csrf', token ? token.value : '');
      datos.append('a', 'subir_firma');
      datos.append('imagen', archivo);

      fetch(S.subirFirma, { method: 'POST', body: datos })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { estado.textContent = 'Error: ' + j.error; return; }
          b.imagen = j.ruta;
          cambio();
          // Se rehace el panel entero: así aparece el botón "Quitar imagen"
          // y el aviso de que ya quedó subida, aunque mientras tanto se haya
          // vuelto a tocar el bloque.
          if (bloques[sel] === b) verProps();
        })
        .catch(function () { estado.textContent = 'No se pudo subir. Revise su conexión.'; });
    });
    fila.appendChild(input);

    if (b.imagen) {
      var quitar = document.createElement('button');
      quitar.type = 'button';
      quitar.className = 'btn btn-sm btn-gris';
      quitar.textContent = 'Quitar imagen';
      quitar.addEventListener('click', function () {
        b.imagen = null;
        estado.textContent = 'Sin imagen: queda la línea en blanco para firmar a mano.';
        cambio();
        verProps();
      });
      fila.appendChild(quitar);
    }
    c.appendChild(fila);
    return c;
  }

  var propsCampos = {};

  /** Clave con la que se agrupan los cambios seguidos sobre un mismo campo. */
  function agrup(nombre) { return 'p' + sel + ':' + nombre; }

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
      var taTexto = document.createElement('textarea');
      taTexto.rows = 3;
      taTexto.maxLength = 1000;
      taTexto.value = b.texto || '';
      taTexto.addEventListener('input', function () { b.texto = taTexto.value; cambio(agrup('texto')); });
      props.appendChild(campo('Texto (un renglón por línea, para listas)', taTexto));
    }

    if (b.tipo === 'caja') {
      var taCaja = document.createElement('textarea');
      taCaja.rows = 3;
      taCaja.maxLength = 500;
      taCaja.value = b.contenido || '';
      taCaja.addEventListener('input', function () { b.contenido = taCaja.value; cambio(agrup('contenido')); });
      props.appendChild(campo('Texto dentro del recuadro (opcional)', taCaja));

      props.appendChild(campo('Color del texto', entrada('color', b.colorTexto || '#FFFFFF',
        function (v) { b.colorTexto = v.toUpperCase(); }, null, agrup('colorTexto'))));

      var mar = document.createElement('label');
      mar.className = 'lienzo-check';
      var chkMar = document.createElement('input');
      chkMar.type = 'checkbox';
      chkMar.checked = !!b.marco;
      chkMar.addEventListener('change', function () { b.marco = chkMar.checked ? 1 : 0; cambio(); });
      mar.appendChild(chkMar);
      mar.appendChild(document.createTextNode(' Con marco (borde)'));
      props.appendChild(mar);
    }

    // El texto de condiciones/notas es de la empresa, no del bloque (los dos
    // sitios donde se coloca comparten el mismo párrafo), pero se edita aquí
    // mismo para no tener que ir a la otra pantalla.
    if (b.tipo === 'parrafo') {
      var ta = document.createElement('textarea');
      ta.rows = 5;
      ta.maxLength = 2000;
      ta.value = b.clave === 'notas' ? (S.notas || '') : (S.condiciones || '');
      ta.addEventListener('input', function () {
        if (b.clave === 'notas') S.notas = ta.value; else S.condiciones = ta.value;
        cambio('parrafoTexto:' + b.clave);
      });
      props.appendChild(campo('Texto (una línea por renglón)', ta));
    }

    // Los rótulos que imprime la pieza —«CLIENTE», «SUBTOTAL»— se escriben
    // aquí: cada empresa los llama a su manera.
    if (S.rotulos[b.tipo]) {
      if (!b.textos) b.textos = rot(b);
      Object.keys(S.rotulos[b.tipo]).forEach(function (k) {
        var def = S.rotulos[b.tipo][k];
        var i = entrada('text', b.textos[k] !== undefined ? b.textos[k] : def[1],
          function (v) { b.textos[k] = v; }, { maxlength: 40 }, agrup('rot:' + k));
        if (b.tipo === 'firmas') i.placeholder = k === 'izq' ? S.firmaIzq : S.firmaDer;
        props.appendChild(campo(def[0], i));
      });
    }

    var rejilla = document.createElement('div');
    rejilla.className = 'props-rejilla';
    propsCampos.x = entrada('number', b.x, function (v) { b.x = +v; }, null, agrup('x'));
    propsCampos.y = entrada('number', b.y, function (v) { b.y = +v; }, null, agrup('y'));
    propsCampos.w = entrada('number', b.w, function (v) { b.w = +v; }, null, agrup('w'));
    rejilla.appendChild(campo('X', propsCampos.x));
    rejilla.appendChild(campo('Y', propsCampos.y));
    rejilla.appendChild(campo('Ancho', propsCampos.w));
    if (b.tipo === 'caja' || b.tipo === 'logo' || b.tipo === 'firma1') {
      propsCampos.h = entrada('number', b.h, function (v) { b.h = +v; }, null, agrup('h'));
      rejilla.appendChild(campo('Alto', propsCampos.h));
    }
    props.appendChild(rejilla);

    if (b.tipo === 'firma1') {
      props.appendChild(subirFirma(b));
    }

    var CON_TAMANO = ['dato', 'texto', 'parrafo', 'cliente', 'totales', 'firmas', 'firma1', 'caja'];
    if (CON_TAMANO.indexOf(b.tipo) !== -1) {
      var r2 = document.createElement('div');
      r2.className = 'props-rejilla';
      r2.appendChild(campo('Tamaño', entrada('number', b.tam,
        function (v) { b.tam = +v; }, { min: 5, max: 40, step: 0.5 }, agrup('tam'))));

      if (b.tipo === 'dato' || b.tipo === 'texto' || b.tipo === 'caja') {
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

    props.appendChild(campo('Color', entrada('color', b.color,
      function (v) { b.color = v.toUpperCase(); }, null, agrup('color'))));

    if (CON_FONDO.indexOf(b.tipo) !== -1) {
      props.appendChild(controlFondo(b));
    }

    var CON_NEGRITA = ['dato', 'texto', 'parrafo', 'cliente', 'totales', 'firmas', 'firma1', 'caja'];
    if (CON_NEGRITA.indexOf(b.tipo) !== -1) {
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

    var dup = document.createElement('button');
    dup.type = 'button';
    dup.className = 'btn btn-sm btn-gris';
    dup.style.marginTop = '12px';
    dup.style.marginRight = '8px';
    dup.textContent = 'Duplicar bloque';
    dup.title = 'También con Ctrl+C y Ctrl+V';
    dup.addEventListener('click', function () { duplicar(sel); });
    props.appendChild(dup);

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
    if (tipo === 'totales' || tipo === 'firmas' || tipo === 'firma1' || tipo === 'parrafo') return ['pie'];
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
      if (b.tipo === 'caja' || b.tipo === 'logo' || b.tipo === 'firma1') {
        b.h = Math.max(2, encajar(arrastre.bh + dy));
      }
    } else {
      b.x = Math.max(0, Math.min(ANCHO_HOJA - 8, encajar(arrastre.bx + dx)));
      b.y = Math.max(0, encajar(arrastre.by + dy));
    }
    pintar();
  });

  // También al perder el foco: si se suelta el ratón fuera de la ventana no
  // llega el pointerup, y el arrastre se quedaría sin anotar en el historial
  // —el bloque movido, pero el siguiente «deshacer» saltando a un estado
  // anterior y llevándose el movimiento por delante—.
  ['pointerup', 'pointercancel', 'blur'].forEach(function (ev) {
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
        x: MARGEN, y: 10, w: tipo === 'totales' ? 190 : (tipo === 'firma1' ? 160 : 200),
        h: tipo === 'logo' ? 58 : (tipo === 'firma1' ? 50 : 20),
        tam: 9, negrita: 0, alin: 'izq', color: tipo === 'caja' ? S.color : '#1F2A36'
      };
      if (tipo === 'texto') b.texto = 'Texto';
      if (tipo === 'cliente' || tipo === 'totales' || tipo === 'firmas' || tipo === 'firma1') b.color = S.color;
      if (tipo === 'firmas' || tipo === 'cliente') b.w = ANCHO_HOJA - MARGEN * 2;
      if (tipo === 'firma1') { b.imagen = null; }

      // Cae en un hueco libre y no encima del último: dos bloques superpuestos
      // al añadirlos parecen uno solo y el de abajo queda imposible de volver
      // a tocar. 4px de diferencia no bastaba —más chico que la propia altura
      // del bloque—, así que varios "Texto fijo" seguidos se tapaban unos a
      // otros por completo. Se cuenta sólo el mismo tipo en la misma zona,
      // para no correr de su sitio a piezas ya acomodadas (totales, firmas...).
      var mismoTipo = bloques.filter(function (o) { return o.zona === zona && o.tipo === tipo; });
      b.x = Math.min(ANCHO_HOJA - 8, MARGEN + mismoTipo.length * 15);
      b.y = 10 + mismoTipo.length * 20;

      bloques.push(b);
      seleccionar(bloques.length - 1);
      cambio();
    });
  });

  // Ctrl+C copia el bloque seleccionado (una copia propia, no el portapapeles
  // del sistema); Ctrl+V lo pega desplazado un poco, para no taparlo.
  var portapapeles = null;

  function duplicar(i) {
    if (i < 0 || !bloques[i]) return;
    var copia = JSON.parse(JSON.stringify(bloques[i]));
    copia.x = Math.min(ANCHO_HOJA - 8, copia.x + 15);
    copia.y = copia.y + 15;
    bloques.push(copia);
    // El portapapeles pasa a ser ESTA copia: si se pega otra vez (Ctrl+V),
    // se desplaza desde aquí, no desde el original. Si no, pegar varias
    // veces seguidas deja todas las copias exactamente superpuestas, tapando
    // las de abajo para siempre.
    portapapeles = JSON.parse(JSON.stringify(copia));
    seleccionar(bloques.length - 1);
    cambio();
  }

  document.addEventListener('keydown', function (ev) {
    if (document.activeElement && /input|select|textarea/i.test(document.activeElement.tagName)) return;

    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'c') {
      if (sel < 0) return;
      portapapeles = JSON.parse(JSON.stringify(bloques[sel]));
      ev.preventDefault();
      return;
    }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'v') {
      if (!portapapeles) return;
      var copia = JSON.parse(JSON.stringify(portapapeles));
      copia.x = Math.min(ANCHO_HOJA - 8, copia.x + 15);
      copia.y = copia.y + 15;
      bloques.push(copia);
      // En cascada: la próxima vez que se pegue, se desplaza desde ESTA
      // copia. Pegar varias veces seguidas (Ctrl+V, Ctrl+V...) sin esto
      // dejaba todas las copias en el mismo sitio, una tapando a la otra.
      portapapeles = JSON.parse(JSON.stringify(copia));
      seleccionar(bloques.length - 1);
      cambio();
      ev.preventDefault();
      return;
    }

    if (sel < 0) return;
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


  // ------------------------------------------------------------- historial
  //
  // Se guardan estados enteros, no las acciones que llevan de uno a otro. Un
  // diseño son unas decenas de bloques —unos pocos kilobytes—, así que copiar
  // el estado sale barato y evita tener que escribir el inverso de cada
  // operación, que es de donde salen los deshacer que dejan el documento a
  // medias.

  var TOPE = 120;              // cuántos pasos atrás se recuerdan
  var historia = [];
  var pos = -1;                // dónde estamos dentro de historia
  var claveUltima = null;
  var horaUltima = 0;
  var restaurando = false;

  function instantanea() {
    return JSON.stringify({ b: bloques, a: altoCabecera });
  }

  /**
   * Anota el estado actual.
   *
   * Con `clave`, los cambios seguidos sobre lo mismo se funden en un solo
   * paso: escribir un rótulo letra a letra tiene que deshacerse de una vez, no
   * carácter a carácter. Sin clave, cada llamada es un paso propio.
   */
  function registrar(clave) {
    if (restaurando) return;

    var ahora = new Date().getTime();
    var estado = instantanea();
    if (pos >= 0 && historia[pos] === estado) return;   // nada cambió

    var funde = clave && clave === claveUltima && (ahora - horaUltima) < 900;
    claveUltima = clave || null;
    horaUltima = ahora;

    if (funde && pos >= 0) {
      historia[pos] = estado;
    } else {
      // Al cambiar algo después de haber deshecho, lo que había por delante
      // deja de tener sentido y se descarta.
      historia = historia.slice(0, pos + 1);
      historia.push(estado);
      if (historia.length > TOPE) historia.shift();
      pos = historia.length - 1;
    }
    botones();
  }

  function restaurar(estado) {
    var d = JSON.parse(estado);
    restaurando = true;
    bloques = d.b;
    altoCabecera = d.a;
    if (sel >= bloques.length) sel = -1;    // el bloque elegido pudo no existir
    claveUltima = null;                     // no fundir con lo que hubiera antes
    pintar();
    verProps();
    previa();
    restaurando = false;
    botones();
  }

  function deshacer() {
    if (pos <= 0) return;
    pos--;
    restaurar(historia[pos]);
  }

  function rehacer() {
    if (pos >= historia.length - 1) return;
    pos++;
    restaurar(historia[pos]);
  }

  function botones() {
    if (btnDeshacer) btnDeshacer.disabled = pos <= 0;
    if (btnRehacer) btnRehacer.disabled = pos >= historia.length - 1;
  }

  if (btnDeshacer) btnDeshacer.addEventListener('click', deshacer);
  if (btnRehacer) btnRehacer.addEventListener('click', rehacer);

  document.addEventListener('keydown', function (ev) {
    if (!(ev.ctrlKey || ev.metaKey)) return;

    // Dentro de una casilla manda el deshacer del navegador: ahí se está
    // escribiendo texto, y quitárselo sorprendería más de lo que ayuda.
    var f = document.activeElement;
    if (f && /input|select|textarea/i.test(f.tagName)) return;

    var tecla = ev.key.toLowerCase();
    if (tecla === 'z' && !ev.shiftKey) { deshacer(); ev.preventDefault(); }
    else if (tecla === 'y' || (tecla === 'z' && ev.shiftKey)) { rehacer(); ev.preventDefault(); }
  });

  // -------------------------------------------------------------- previa

  var espera = null;

  function cambio(clave) {
    registrar(clave);
    pintar();
    previa();
  }

  function previa() {
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
     ['alto_cabecera', altoCabecera], ['bloques', JSON.stringify(bloques)],
     ['condiciones', S.condiciones || ''], ['notas', S.notas || '']
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
  registrar();          // el punto de partida, para poder volver a él
  dibujarPrevia();
})();
