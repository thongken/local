define(['core/ajax'], function (ajax) {
    return {
        init: function (opts) {
            window.__mlCoreAjax = ajax;
            var root = document.querySelector('[data-kind="' + opts.kind + '"]');
            if (!root) {
                return;
            }
            var entry = opts.kind === 'narrationaudio' ? 'narrationaudio.js' : (opts.courseid ? 'coursequiz.js' : 'app.js');
            import(opts.distUrl + entry + '?v=' + opts.rev).then(function (mod) {
                mod.init(root, opts);
            }).catch(function (e) {
                root.textContent = opts.strings?.loaderror || 'Khong nap duoc ung dung (' + entry + '): ' +
                    (e && e.message ? e.message : e);
            });
        }
    };
});
