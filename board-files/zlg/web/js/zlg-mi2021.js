/*
 * zlg-mi2021.js: the Advanced page (NEP's MI Setup 20230903) for the 800B
 * inverters (module IDs 33xxxxxx) on grid codes 1 and 6.
 *
 * These inverters keep their grid settings in the encoding of firmware
 * 20210303's MI Setup, not the one of 20230903's (tested on 2026-11-08: a
 * trip limit written as 0x46 tripped at 242 V, i.e. 236 V as 2021 reads it,
 * not 257 V as 2023 does). For them this file replaces NEP's decoding (after
 * proc() fills the form) and NEP's mwrite():
 *   V  = 220 +- b * 4 / AC_V_Gain        (b = |V - 220| * gain / 4)
 *   f  = nominal +- b / 32
 *   trip times: one 16-bit word per pair, (fast x 10) << 11 | (slow x 10)
 *   reconnect time = b * 2.048 s; written as (t - 33) / 2.048, which the
 *   inverter stores as that + 16
 * A write starts from the module's last reply (Check it first) and changes
 * only the checked fields, so nothing depends on how the inverter treats a
 * zero byte. The frequency calibration byte is always 00 here: the inverter
 * takes it as the actual grid frequency and recalibrates (zlg-admin
 * --mi-calibrate does that). Other models and grid codes stay NEP's code.
 * For all models, Extended Set keeps the form for every module of a range
 * and asks before sending unticked sections as 00 (end of this file).
 */
(function () {
	'use strict';
	var GRIDS = { 1: true, 6: true };
	var V_OVER = ['smaxv', 'maxv', 'maxv2', 'maxv3'], V_UNDER = ['sminv', 'minv', 'minv2', 'minv3'];
	var F_OVER = ['smaxf', 'maxf', 'maxf2', 'maxf3'], F_UNDER = ['sminf', 'minf', 'minf2', 'minf3'];
	/* command byte of each field; the reply holds byte k at k + 8 with each
	   pair of bytes swapped (command 6/7 = reply 15/14) */
	var CMD = { pwr: 8, rect: 9, minv: 10, maxv: 11, minv2: 12, maxv2: 13, minv3: 14, maxv3: 15,
		minf: 16, maxf: 17, minf2: 18, maxf2: 19, minf3: 20, maxf3: 21, sminv: 30, smaxv: 31,
		sminf: 32, smaxf: 33 };
	/* trip-time words: [high byte, fast field, slow field] */
	var TIMES = [[22, 'mintv2', 'mintv3'], [24, 'maxtv2', 'maxtv3'], [26, 'mintf2', 'mintf3'],
		[28, 'maxtf2', 'maxtf3']];
	var MID = 220, POWER_GAIN = 4, RECT_STORED_PLUS = 16;
	var last = {};	/* module index -> reply bytes of its last read or write */

	function rbyte(r, k) { return r[(k ^ 1) + 8]; }	/* command byte k as the reply holds it */
	function fix(x, d) { return (Math.round(x * Math.pow(10, d)) / Math.pow(10, d)).toFixed(d); }

	var Z = window.zlgMi2021 = {
		applies: function (id, grid) { return String(id).substring(0, 2) === '33' && GRIDS[parseInt(grid, 10)] === true; },
		bytes: function (data) {
			var h = String(data).trim().split(/\s+/);
			return h.length === 44 ? h.map(function (x) { return parseInt(x, 16); }) : null;
		},
		/* the reply's settings as the form shows them */
		decode: function (r, gain, fre, reconnOffset) {
			var v = {}, i;
			for (i = 0; i < 4; i++) {
				v[V_OVER[i]] = fix(MID + rbyte(r, CMD[V_OVER[i]]) * 4 / gain, 1);
				v[V_UNDER[i]] = fix(MID - rbyte(r, CMD[V_UNDER[i]]) * 4 / gain, 1);
				v[F_OVER[i]] = fix(fre + rbyte(r, CMD[F_OVER[i]]) / 32, 2);
				v[F_UNDER[i]] = fix(fre - rbyte(r, CMD[F_UNDER[i]]) / 32, 2);
			}
			TIMES.forEach(function (t) {
				var w = rbyte(r, t[0]) * 256 + rbyte(r, t[0] + 1);
				v[t[1]] = fix((w >> 11) / 10, 1);
				v[t[2]] = fix((w & 0x7ff) / 10, 1);
			});
			v.rect = fix(Math.round(r[16] * 2.048 * 0.4) / 0.4 + reconnOffset, 0);
			v.pwr = String(r[17] * POWER_GAIN);
			var c = r[15] > 127 ? r[15] - 256 : r[15];
			v.fcal = (c >= 0 ? '+' : '') + fix(c / 32, 3);	/* frequency calibration, Hz */
			v.grid = String(r[14]);
			return v;
		},
		/* the command that writes `set` (field -> number) over the reply `r`;
		   {cmd, errors, notes} */
		encode: function (r, set, gain, fre, reconnOffset) {
			var c = [0, 1, 0, 0, 0, 0], errors = [], notes = [], k;
			for (k = 6; k < 34; k++)
				c[k] = rbyte(r, k);
			c[6] = 0;	/* frequency calibration: never from here */
			c[9] = Math.max(0, r[16] - RECT_STORED_PLUS);	/* keeps the stored reconnect time */
			var step = 4 / gain;
			function volt(n, over) {
				/* by the byte it encodes to (1 to 255), so the limits as
				   printed (278.5, 161.5 V) are accepted */
				var x = set[n], b = Math.round(Math.abs(x - MID) / step);
				if ((over ? !(x > MID) : !(x < MID)) || !(b >= 1 && b <= 255)) {
					errors.push(n + ' ' + x + ' V: this encoding holds ' + (over ? fix(MID + step, 1) + ' to ' + fix(MID + 255 * step, 1)
						: fix(MID - 255 * step, 1) + ' to ' + fix(MID - step, 1)) + ' V');
					return;
				}
				c[CMD[n]] = b;
				var back = MID + (over ? 1 : -1) * c[CMD[n]] * 4 / gain;
				if (Math.abs(back - x) >= 0.05)
					notes.push(n + ' ' + x + ' V is written as ' + fix(back, 1) + ' V');
			}
			function freq(n, over) {
				var x = set[n], b = Math.round(Math.abs(x - fre) * 32);
				if ((over ? x <= fre : x >= fre) || b > 255) {
					errors.push(n + ' ' + x + ' Hz: this encoding holds ' + (over ? (fre + 1 / 32).toFixed(2) + ' to ' + fix(fre + 255 / 32, 2)
						: fix(fre - 255 / 32, 2) + ' to ' + (fre - 1 / 32).toFixed(2)) + ' Hz');
					return;
				}
				c[CMD[n]] = b;
				var back = fre + (over ? b : -b) / 32;
				if (Math.abs(back - x) >= 0.005)
					notes.push(n + ' ' + x + ' Hz is written as ' + fix(back, 3) + ' Hz');
			}
			for (k = 0; k < 4; k++) {
				if (V_OVER[k] in set) volt(V_OVER[k], true);
				if (V_UNDER[k] in set) volt(V_UNDER[k], false);
				if (F_OVER[k] in set) freq(F_OVER[k], true);
				if (F_UNDER[k] in set) freq(F_UNDER[k], false);
			}
			TIMES.forEach(function (t) {
				var w = c[t[0]] * 256 + c[t[0] + 1], fast = w >> 11, slow = w & 0x7ff;
				if (t[1] in set) {
					fast = Math.floor(set[t[1]] * 10 + 1e-6);
					if (!(fast >= 0 && fast <= 31)) { errors.push(t[1] + ' ' + set[t[1]] + ' s: this encoding holds 0 to 3.1 s'); fast = 0; }
					else if (Math.abs(fast / 10 - set[t[1]]) > 1e-6) notes.push(t[1] + ' ' + set[t[1]] + ' s is written as ' + fix(fast / 10, 1) + ' s');
				}
				if (t[2] in set) {
					slow = Math.floor(set[t[2]] * 10 + 1e-6);
					if (!(slow >= 0 && slow <= 2047)) { errors.push(t[2] + ' ' + set[t[2]] + ' s: this encoding holds 0 to 204.7 s'); slow = 0; }
					else if (Math.abs(slow / 10 - set[t[2]]) > 1e-6) notes.push(t[2] + ' ' + set[t[2]] + ' s is written as ' + fix(slow / 10, 1) + ' s');
				}
				w = (fast << 11) | slow;
				c[t[0]] = w >> 8;
				c[t[0] + 1] = w & 255;
			});
			if ('rect' in set) {
				var t = Math.round((set.rect - reconnOffset) * 0.4) / 0.4;
				var b = Math.round((t - 33) * 1000 / 2048);
				if (!(b >= 4 && b + RECT_STORED_PLUS <= 255))
					errors.push('Reconnect Time ' + set.rect + ' s: this encoding holds 40 to 555 s');
				else
					c[9] = b;
			}
			if ('pwr' in set) {
				var p = Math.round(set.pwr / POWER_GAIN);
				if (!(p >= 25 && p <= 255)) errors.push('MAX_output_pwr ' + set.pwr + ' W: 100 to 1020 W');
				else c[8] = p;
			}
			if ('grid' in set)
				c[7] = set.grid;
			return { cmd: c.map(function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join(''),
				errors: errors, notes: notes };
		}
	};

	/* ---- the page ---- */
	if (typeof window.proc !== 'function' || typeof window.mwrite !== 'function')
		return;
	function id(ind) { return String(plc_ids[parseInt(ind, 10)] || ''); }
	function gain() { return parseFloat(ac_v_gains[3]); }
	function fre() { return parseFloat($('#fre').val()) || 60; }
	function roff() { return parseFloat(reconn_time_offset) || 0; }

	var nepProc = window.proc;
	window.proc = function (data, inde, typ) {
		var ok = nepProc(data, inde, typ);
		var r = ok ? Z.bytes(data) : null;
		if (!r || !Z.applies(id(inde), r[14]))
			return ok;
		last[parseInt(inde, 10)] = r;
		var v = Z.decode(r, gain(), fre(), roff());
		if (typ === 0) {
			Object.keys(v).forEach(function (k) {
				if (k !== 'grid' && k !== 'fcal') $('#' + k).val(v[k]);
			});
			$('#pwr_val').val(v.pwr);
			$('#fref').val(''); $('#fref_val').val('');
		}
		sc('<font color=blue>Module ' + inde + ' (800B, grid ' + v.grid + '): decoded in the 2021 encoding: ' +
			'VAC_MAX_2 ' + v.maxv2 + ' V / ' + v.maxtv2 + ' s, VAC_MAX_3 ' + v.maxv3 + ' V / ' + v.maxtv3 + ' s, ' +
			'VAC_MIN_3 ' + v.minv3 + ' V / ' + v.mintv3 + ' s, VAC_MIN_2 ' + v.minv2 + ' V / ' + v.mintv2 + ' s, ' +
			'FREQ_MAX_2 ' + v.maxf2 + ' Hz / ' + v.maxtf2 + ' s, FREQ_MAX_3 ' + v.maxf3 + ' Hz / ' + v.maxtf3 + ' s, ' +
			'FREQ_MIN_3 ' + v.minf3 + ' Hz / ' + v.mintf3 + ' s, FREQ_MIN_2 ' + v.minf2 + ' Hz / ' + v.mintf2 + ' s, ' +
			'reconnect ' + v.rect + ' s, power ' + v.pwr + ' W, frequency calibration ' + v.fcal + ' Hz.</font><br>');
		return ok;
	};

	/* the checked fields of the Basic tab, as NEP's mwrite() picks them */
	function checked() {
		var set = {}, bad = [];
		['maxv', 'minv', 'maxf', 'minf'].forEach(function (g) {
			$('input[type="checkbox"][name="' + g + '"]:checked').each(function () {
				var n = $(this).val();
				if (!(n in CMD)) return;	/* Japan's fields */
				var x = parseFloat($('#' + n).val());
				if (isNaN(x)) bad.push(n); else set[n] = x;
				var tp = { maxv2: 'maxtv2', maxv3: 'maxtv3', minv2: 'mintv2', minv3: 'mintv3',
					maxf2: 'maxtf2', maxf3: 'maxtf3', minf2: 'mintf2', minf3: 'mintf3' }[n];
				if (tp) {
					var t = parseFloat($('#' + tp).val());
					if (isNaN(t)) bad.push(tp); else set[tp] = t;
				}
			});
		});
		if ($('#chk_rect').is(':checked')) set.rect = parseFloat($('#rect').val());
		if ($('#chk_pwr').is(':checked')) set.pwr = parseFloat($('#pwr').val());
		if (isNaN(set.rect)) delete set.rect;
		if (isNaN(set.pwr)) delete set.pwr;
		return { set: set, bad: bad, fref: $('#chk_fref').is(':checked') };
	}

	var nepMwrite = window.mwrite;
	window.mwrite = function (cnt, nam) {
		var grid = $('#grid').val();
		if (String(id(nam)).substring(0, 2) !== '33')
			return nepMwrite(cnt, nam);
		function stop(msg) { $('#cons').unblock(); if (msg) alert(msg); }
		if ($('#plcCnt').html() !== '') return stop('Enter Setup Mode First.');
		if (parseInt($('#ind_1').val(), 10) > parseInt($('#ind_2').val(), 10))
			return stop('End module index should not be less than start.');
		if (parseInt(nam, 10) > parseInt($('#ind_2').val(), 10)) return;
		if (!GRIDS[parseInt(grid, 10)])
			return stop('Module ' + nam + ' is an 800B inverter: this page writes its basic settings only with grid code ' +
				'6 (1547.1 240/120V) or 1 (IEEE 1547), in the encoding these inverters use.');
		var r = last[parseInt(nam, 10)];
		if (!r) return stop('Check module ' + nam + ' first: a write keeps its current values for the fields that ' +
			'aren\'t checked.');
		var ch = checked();
		if (ch.fref) return stop('Freq_cal_offset isn\'t written from this page for 800B inverters: the inverter ' +
			'recalibrates its frequency reading from it (sudo zlg-admin --mi-calibrate).');
		if (ch.bad.length) return stop('No value for: ' + ch.bad.join(', ') + '.');
		ch.set.grid = parseInt(grid, 10);
		var e = Z.encode(r, ch.set, gain(), fre(), roff());
		if (e.errors.length) return stop('Module ' + nam + ': not written.\n\n' + e.errors.join('\n'));
		if (e.notes.length) sc('<font color=gray>Module ' + nam + ': ' + e.notes.join('; ') + '.</font><br>');
		$('#the_cnt').text(cnt);
		sc('<font color=red>Send Parameters to MI:' + nam + ' (2021 encoding) ...OK</font><br>' +
			(plcset_debug ? e.cmd + '<br>' : ''));
		$.post('/nep/advanced/proxy?' + (new Date()).valueOf(),
			{ mod: write, pwd: $('#pwd').val(), ind: nam, cmd: e.cmd, enc: '2021' },
			function (result) {
				var res = eval('(' + result + ')');
				if (parseInt(res.type, 10) === 0) return stop(res.data);
				if (proc(res.data, nam, 1)) {
					nam = parseInt(nam, 10) + 1;
					if (nam <= parseInt($('#ind_2').val(), 10)) mwrite(0, nam); else stop();
				} else {
					sc('<font color=green>Recv Response from MI:' + nam + ' ...</font><font color=red>An Error Occurred,' +
						'Try Again.' + cnt + ' Times.</font><br>');
					if (cnt + 1 < retry) return mwrite(cnt + 1, nam);
					nam = parseInt(nam, 10) + 1;
					if (nam <= parseInt($('#ind_2').val(), 10)) mwrite(0, nam); else stop();
				}
			});
	};

	/* ---- Extended Set (NEP's mwrite_extend, all models) ----
	 * 1. NEP's page refreshes the form from each module's reply
	 *    (proc_extend), which unticks V_Norm / Ramp Delay among others, so a
	 *    range write sent those as 00 to every module after the first. The
	 *    form as it was when Set was pressed is restored before each module.
	 * 2. Every unticked section goes out as 00, and the inverter stores
	 *    some of those zeros (seen on the 800B: fixed power factor, watt-PF
	 *    points, the volt-watt control byte): ask before writing. */
	if (typeof window.mwrite_extend !== 'function')
		return;
	/* Temperature MAX and CTRL FLAG aren't listed: the 800B keeps them when
	   they come as 00 (2026-11-09) */
	var EXT_SECTIONS = [['chk_vnorm', 'V_Norm / Ramp Delay'], ['chk_freq_watt', 'FREQ-WATT'],
		['chk_volt_watt', 'VOLT-WATT'], ['chk_var_ctrl', 'VAR-CTRL']];
	var form = null;	/* the form when Set was pressed */
	function take() {
		var s = [];
		$('input, select, textarea').each(function () {
			if (!this.id || /^(ind_1|ind_2|pwd)$/.test(this.id)) return;
			var box = this.type === 'checkbox' || this.type === 'radio';
			s.push([this, box, box ? this.checked : $(this).val()]);
		});
		return s;
	}
	function put(s) {
		s.forEach(function (e) { if (e[1] && !e[2]) e[0].checked = false; });
		s.forEach(function (e) { if (e[1]) { if (e[2]) e[0].checked = true; } else $(e[0]).val(e[2]); });
	}
	var nepMwriteExtend = window.mwrite_extend;
	window.mwrite_extend = function (cnt, nam) {
		if (parseInt(nam, 10) === parseInt($('#ind_1').val(), 10) && !cnt) {
			var off = EXT_SECTIONS.filter(function (x) { return $('#' + x[0]).length && !$('#' + x[0]).is(':checked'); })
				.map(function (x) { return x[1]; });
			if (off.length && !confirm('Not ticked: ' + off.join(', ') + '.\n\nNEP\'s Extended Set sends these as 00, ' +
				'not "unchanged": the inverter may store zeros for them (on 800B inverters: the fixed power ' +
				'factor, the watt-PF points and the volt-watt control byte; Temperature MAX and CTRL FLAG ' +
				'are kept). To keep a section, Check the module and tick it.\n\nWrite anyway?')) {
				$('#cons').unblock();
				return;
			}
			form = take();
		} else if (form) {
			put(form);	/* NEP's reply handling unticked parts of it */
		}
		return nepMwriteExtend(cnt, nam);
	};
})();
