(function () {
    function isEraseStroke(stroke) {
        if (!stroke || typeof stroke !== 'object') {
            return false;
        }

        const tool = String(stroke.tool || '').toLowerCase();
        if (tool === 'erase') {
            return true;
        }

        const color = String(stroke.color || '').toUpperCase();
        return !stroke.tool && color === '#FFFFFF';
    }

    function patchSketchApp() {
        if (typeof SketchApp !== 'function') {
            return false;
        }

        const proto = SketchApp.prototype;
        if (proto.__eraserPatchApplied) {
            return true;
        }
        proto.__eraserPatchApplied = true;

        const originalRender = proto.render;
        const originalDrawStroke = proto.drawStroke;
        const originalGetStrokeBounds = proto.getStrokeBounds;

        proto.render = function () {
            if (!this.canvas || !this.ctx) {
                if (typeof originalRender === 'function') {
                    return originalRender.call(this);
                }
                return;
            }

            if (!this.strokeCanvas) {
                this.strokeCanvas = document.createElement('canvas');
                this.strokeCtx = this.strokeCanvas.getContext('2d', { alpha: true });
            }

            if (!this.strokeCtx) {
                if (typeof originalRender === 'function') {
                    return originalRender.call(this);
                }
                return;
            }

            if (this.strokeCanvas.width !== this.canvas.width || this.strokeCanvas.height !== this.canvas.height) {
                this.strokeCanvas.width = this.canvas.width;
                this.strokeCanvas.height = this.canvas.height;
            }

            const ctx = this.ctx;
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.fillStyle = '#fdfaf2';
            ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            this.renderBackground(ctx);

            const strokeCtx = this.strokeCtx;
            strokeCtx.setTransform(1, 0, 0, 1, 0, 0);
            strokeCtx.clearRect(0, 0, this.strokeCanvas.width, this.strokeCanvas.height);
            strokeCtx.setTransform(this.scale * this.dpr, 0, 0, this.scale * this.dpr, this.offset.x, this.offset.y);

            for (const stroke of this.strokes.values()) {
                this.drawStroke(strokeCtx, stroke);
            }
            for (const stroke of this.liveRemoteStrokes.values()) {
                this.drawStroke(strokeCtx, stroke);
            }
            if (this.currentStroke) {
                this.drawStroke(strokeCtx, this.currentStroke);
            }

            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.drawImage(this.strokeCanvas, 0, 0);
            this.renderRemoteCursors();
        };

        proto.drawStroke = function (ctx, stroke) {
            if (isEraseStroke(stroke)) {
                const previousComposite = ctx.globalCompositeOperation;
                ctx.globalCompositeOperation = 'destination-out';
                try {
                    return originalDrawStroke.call(this, ctx, stroke);
                } finally {
                    ctx.globalCompositeOperation = previousComposite;
                }
            }

            return originalDrawStroke.call(this, ctx, stroke);
        };

        proto.getStrokeBounds = function (stroke) {
            if (isEraseStroke(stroke)) {
                return null;
            }

            return originalGetStrokeBounds.call(this, stroke);
        };

        return true;
    }

    patchSketchApp();
    window.addEventListener('load', patchSketchApp, { once: true });
})();
