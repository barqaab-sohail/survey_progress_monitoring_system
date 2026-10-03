(function () {
    'use strict';

    const data = window.managementDashboardData;
    if (! data) return;

    const colors = {
        ink: '#182230',
        muted: '#667085',
        line: '#d9e0e8',
        track: '#edf1f5',
        survey: '#2563eb',
        mdb: '#16a34a',
        danger: '#ef4444',
        amber: '#f59e0b',
    };

    const number = value => new Intl.NumberFormat().format(Math.round(Number(value) || 0));
    const percent = value => `${Number(value || 0).toFixed(1)}%`;

    function setup(id, dynamicHeight) {
        const canvas = document.getElementById(id);
        if (! canvas) return null;
        if (dynamicHeight) canvas.style.height = `${dynamicHeight}px`;
        const box = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, box.width * ratio);
        canvas.height = Math.max(1, box.height * ratio);
        const context = canvas.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, box.width, box.height);

        return { canvas, context, width: box.width, height: box.height };
    }

    function roundedRect(context, x, y, width, height, radius, fill) {
        const safeRadius = Math.min(radius, height / 2, width / 2);
        context.beginPath();
        context.moveTo(x + safeRadius, y);
        context.arcTo(x + width, y, x + width, y + height, safeRadius);
        context.arcTo(x + width, y + height, x, y + height, safeRadius);
        context.arcTo(x, y + height, x, y, safeRadius);
        context.arcTo(x, y, x + width, y, safeRadius);
        context.closePath();
        context.fillStyle = fill;
        context.fill();
    }

    function drawOverall() {
        const chart = setup('overallProgressChart');
        if (! chart) return;
        const { context, width, height } = chart;
        const mobile = width < 560;
        const left = mobile ? 102 : 145;
        const right = mobile ? 52 : 72;
        const top = 30;
        const bottom = 20;
        const plotWidth = Math.max(width - left - right, 40);
        const rowHeight = (height - top - bottom) / data.overall.length;

        context.font = '11px sans-serif';
        context.fillStyle = colors.muted;
        [0, 25, 50, 75, 100].forEach(tick => {
            const x = left + plotWidth * tick / 100;
            context.strokeStyle = tick === 0 ? colors.line : '#edf1f5';
            context.beginPath();
            context.moveTo(x, top - 15);
            context.lineTo(x, height - bottom);
            context.stroke();
            context.textAlign = tick === 0 ? 'left' : tick === 100 ? 'right' : 'center';
            context.fillText(`${tick}%`, x, 11);
        });

        data.overall.forEach((row, index) => {
            const centerY = top + rowHeight * index + rowHeight / 2;
            const barHeight = mobile ? 22 : 28;
            context.textAlign = 'left';
            context.fillStyle = colors.ink;
            context.font = `700 ${mobile ? 11 : 13}px sans-serif`;
            context.fillText(row.label, 0, centerY - 3);
            context.fillStyle = colors.muted;
            context.font = '11px sans-serif';
            context.fillText(number(row.value), 0, centerY + 14);
            roundedRect(context, left, centerY - barHeight / 2, plotWidth, barHeight, barHeight / 2, colors.track);
            roundedRect(context, left, centerY - barHeight / 2, Math.max(plotWidth * Math.min(row.percent, 100) / 100, 3), barHeight, barHeight / 2, row.color);
            context.fillStyle = colors.ink;
            context.font = `800 ${mobile ? 13 : 16}px sans-serif`;
            context.textAlign = 'right';
            context.fillText(percent(row.percent), width, centerY + 5);
        });
    }

    function drawDonut(id, rows, legendId, completedSegments) {
        const chart = setup(id);
        if (! chart) return;
        const { context, width, height } = chart;
        const total = rows.reduce((sum, row) => sum + Number(row.value || 0), 0);
        const completed = rows.slice(0, completedSegments).reduce((sum, row) => sum + Number(row.value || 0), 0);
        const centerX = width / 2;
        const centerY = height / 2;
        const radius = Math.min(width, height) * 0.33;
        const thickness = Math.max(24, radius * 0.34);

        context.lineWidth = thickness;
        context.lineCap = 'butt';
        let start = -Math.PI / 2;
        if (! total) {
            context.strokeStyle = colors.track;
            context.beginPath();
            context.arc(centerX, centerY, radius, 0, Math.PI * 2);
            context.stroke();
        } else {
            rows.forEach(row => {
                const arc = Math.PI * 2 * Number(row.value || 0) / total;
                context.strokeStyle = row.color;
                context.beginPath();
                context.arc(centerX, centerY, radius, start, start + arc);
                context.stroke();
                start += arc;
            });
        }

        context.textAlign = 'center';
        context.fillStyle = colors.ink;
        context.font = '800 26px sans-serif';
        context.fillText(percent(total ? completed / total * 100 : 0), centerX, centerY - 2);
        context.fillStyle = colors.muted;
        context.font = '11px sans-serif';
        context.fillText('COMPLETE', centerX, centerY + 18);

        const legend = document.getElementById(legendId);
        if (legend) {
            legend.innerHTML = rows.map(row => {
                const share = total ? Number(row.value || 0) / total * 100 : 0;
                return `<div><i style="background:${row.color}"></i><span>${row.label}<small>${number(row.value)} · ${percent(share)}</small></span></div>`;
            }).join('');
        }
    }

    function drawClearance() {
        const chart = setup('clearanceChart');
        if (! chart) return;
        const { context, width, height } = chart;
        const rows = data.clearance;
        const max = Math.max(1, ...rows.map(row => Number(row.value || 0)));
        const bottom = height - 38;
        const top = 25;
        const plotHeight = bottom - top;
        const barWidth = Math.min(76, width / 4.5);

        context.strokeStyle = colors.line;
        context.beginPath();
        context.moveTo(20, bottom);
        context.lineTo(width - 20, bottom);
        context.stroke();

        rows.forEach((row, index) => {
            const centerX = width * (index === 0 ? 0.31 : 0.69);
            const value = Number(row.value || 0);
            const barHeight = value ? Math.max(plotHeight * value / max, 4) : 4;
            roundedRect(context, centerX - barWidth / 2, bottom - barHeight, barWidth, barHeight, 9, value ? row.color : colors.track);
            context.textAlign = 'center';
            context.fillStyle = colors.ink;
            context.font = '800 22px sans-serif';
            context.fillText(value ? `${number(value)} days` : 'N/A', centerX, bottom - barHeight - 10);
            context.fillStyle = colors.muted;
            context.font = '11px sans-serif';
            context.fillText(row.label, centerX, height - 12);
        });
    }

    function drawComparison(id, rows, isFeeder) {
        const rowHeight = isFeeder ? 48 : 68;
        const chart = setup(id, Math.max(isFeeder ? 300 : 210, rows.length * rowHeight + 54));
        if (! chart) return;
        const { context, width, height } = chart;
        if (! rows.length) {
            context.fillStyle = colors.muted;
            context.font = '13px sans-serif';
            context.textAlign = 'center';
            context.fillText('No active backlog', width / 2, height / 2);
            return;
        }

        const labelWidth = Math.min(isFeeder ? 88 : 155, width * 0.32);
        const right = 48;
        const plotWidth = Math.max(width - labelWidth - right, 40);
        const max = Math.max(1, ...rows.flatMap(row => [Number(row.survey || 0), Number(row.mdb || 0)]));
        const top = 26;
        const usable = height - top - 10;
        const actualRowHeight = usable / rows.length;

        [0, 0.5, 1].forEach(part => {
            const x = labelWidth + plotWidth * part;
            context.strokeStyle = '#edf1f5';
            context.beginPath();
            context.moveTo(x, 8);
            context.lineTo(x, height - 5);
            context.stroke();
            context.fillStyle = colors.muted;
            context.font = '10px sans-serif';
            context.textAlign = part === 0 ? 'left' : part === 1 ? 'right' : 'center';
            context.fillText(number(max * part), x, 10);
        });

        rows.forEach((row, index) => {
            const centerY = top + actualRowHeight * index + actualRowHeight / 2;
            const label = String(row.label || 'Unknown');
            context.textAlign = 'left';
            context.fillStyle = colors.ink;
            context.font = `700 ${isFeeder ? 11 : 12}px sans-serif`;
            context.fillText(label.length > 20 ? `${label.slice(0, 19)}…` : label, 0, centerY + 3);

            const barHeight = Math.min(12, actualRowHeight * 0.25);
            const surveyY = centerY - barHeight - 2;
            const mdbY = centerY + 2;
            const surveyWidth = plotWidth * Number(row.survey || 0) / max;
            const mdbWidth = plotWidth * Number(row.mdb || 0) / max;
            roundedRect(context, labelWidth, surveyY, Math.max(surveyWidth, 2), barHeight, 5, colors.survey);
            roundedRect(context, labelWidth, mdbY, Math.max(mdbWidth, 2), barHeight, 5, colors.danger);

            context.font = '700 10px sans-serif';
            context.fillStyle = colors.survey;
            context.textAlign = 'left';
            context.fillText(number(row.survey), Math.min(labelWidth + surveyWidth + 5, width - 34), surveyY + barHeight - 1);
            context.fillStyle = colors.danger;
            context.fillText(number(row.mdb), Math.min(labelWidth + mdbWidth + 5, width - 34), mdbY + barHeight - 1);
        });
    }

    function drawTrend() {
        const trend = data.trend || [];
        const chart = setup('trendChart');
        if (! chart) return;
        const { context, width, height } = chart;
        const pad = { left: 46, right: 16, top: 18, bottom: 32 };
        const max = Math.max(1, ...trend.flatMap(row => [row.survey, row.mdb, row.processed]));
        context.font = '11px sans-serif';
        for (let index = 0; index <= 4; index++) {
            const y = pad.top + (height - pad.top - pad.bottom) * index / 4;
            context.strokeStyle = colors.line;
            context.beginPath();
            context.moveTo(pad.left, y);
            context.lineTo(width - pad.right, y);
            context.stroke();
            context.fillStyle = colors.muted;
            context.fillText(number(max * (4 - index) / 4), 3, y + 4);
        }

        [['survey', colors.survey], ['mdb', colors.mdb], ['processed', colors.amber]].forEach(([key, color]) => {
            context.strokeStyle = color;
            context.lineWidth = 3;
            context.lineJoin = 'round';
            context.beginPath();
            trend.forEach((row, index) => {
                const x = pad.left + (width - pad.left - pad.right) * (trend.length === 1 ? 0 : index / (trend.length - 1));
                const y = height - pad.bottom - (height - pad.top - pad.bottom) * row[key] / max;
                index ? context.lineTo(x, y) : context.moveTo(x, y);
            });
            context.stroke();
        });

        if (trend.length) {
            context.fillStyle = colors.muted;
            context.textAlign = 'left';
            context.fillText(trend[0].date.slice(5), pad.left, height - 8);
            context.textAlign = 'right';
            context.fillText(trend.at(-1).date.slice(5), width - pad.right, height - 8);
        }
    }

    function drawAll() {
        drawOverall();
        drawDonut('surveyStatusChart', data.survey, 'surveyLegend', 2);
        drawDonut('mdbStatusChart', data.mdb, 'mdbLegend', 1);
        drawClearance();
        drawComparison('circleBacklogChart', data.circles, false);
        drawComparison('feederBacklogChart', data.feeders, true);
        drawTrend();
    }

    let resizeFrame;
    window.addEventListener('resize', () => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(drawAll);
    });
    drawAll();
})();
