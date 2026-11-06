/*
 * Highcharts 13 defaults matching what NEP's charts looked like with
 * Highcharts 2.3.3: 24-hour times on the date axes (13 uses the browser's
 * locale, e.g. "06:00 AM"). NEP's times are local times labelled UTC, so
 * charts stay on UTC (13's default).
 */
Highcharts.setOptions({
	time: { timezone: 'UTC' },
	xAxis: {
		dateTimeLabelFormats: {
			millisecond: '%H:%M:%S.%L',
			second: '%H:%M:%S',
			minute: '%H:%M',
			hour: '%H:%M',
			day: '%e. %b',
			week: '%e. %b',
			month: '%b \'%y',
			year: '%Y'
		}
	},
	tooltip: {
		dateTimeLabelFormats: {
			minute: '%A, %b %e, %H:%M',
			hour: '%A, %b %e, %H:%M'
		}
	}
});
