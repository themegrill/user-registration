/**
 * The Connect an AI Assistant drawer on Settings, Advanced, AI Integration.
 *
 * Runs the readiness check, makes the AI user and its password in one click once the admin agrees, and shows
 * what the chosen AI app needs.
 */
(function ($) {
	"use strict";

	var cfg = window.urAiConnect || {};
	var i18n = cfg.i18n || {};
	var apps = cfg.apps || {};
	var current = "claude-code";

	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, security: cfg.nonce }, data || {}));
	}

	function message(response) {
		return response && response.data && response.data.message ? response.data.message : i18n.failed;
	}

	function notice(text) {
		$("#ur-ai-result").text(text).prop("hidden", !text);
	}

	function copyText(text, button) {
		var done = function () {
			var label = $(button).data("label") || $(button).text();
			$(button).data("label", label).text(i18n.copied);
			setTimeout(function () {
				$(button).text(label);
			}, 1500);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallbackCopy(text);
				done();
			});
			return;
		}

		fallbackCopy(text);
		done();
	}

	function fallbackCopy(text) {
		var area = $("<textarea>").val(text).css({ position: "fixed", opacity: 0 }).appendTo("body");
		area[0].select();
		try {
			document.execCommand("copy");
		} catch (e) {
			// The text stays on screen to copy by hand.
		}
		area.remove();
	}

	// A fresh tab starts from nothing: nothing generated, nothing agreed to. Each app gets its own password,
	// named after it, so the activity log can tell the apps apart.
	function selectApp(key) {
		var app = apps[key];

		if (!app) {
			return;
		}

		current = key;
		$(".ur-ai-apps__tab").each(function () {
			$(this).toggleClass("is-active", $(this).data("app") === key);
		});
		$("#ur-ai-panel-title").text(i18n.title.replace("%s", app.label));
		$("#ur-ai-panel-intro").text(app.intro);
		$("#ur-ai-consent").prop("checked", false);
		$("#ur-ai-create").prop("disabled", true);
		$("#ur-ai-prompt").text("");
		$("#ur-ai-copy-prompt, #ur-ai-copy-pass, #ur-ai-once").prop("hidden", true);
		$("#ur-ai-m-user, #ur-ai-m-pass").text("-");
		notice("");
	}

	function showResult(data) {
		$("#ur-ai-prompt").text(data.snippets[apps[current].snippet]);
		$("#ur-ai-copy-prompt, #ur-ai-copy-pass, #ur-ai-once").prop("hidden", false);
		$("#ur-ai-m-user").text(data.login);
		$("#ur-ai-m-pass").text(data.password);
		$("#ur-ai-m-endpoint").text(data.endpoint);
		$("#ur-ai-copy-pass").data("password", data.password.replace(/\s+/g, ""));
	}

	// The drawer lives outside the settings form, so nothing in it is ever saved with the settings.
	$("#ur-ai-overlay, #ur-ai-drawer").appendTo("body");
	selectApp(current);

	function openDrawer() {
		$("#ur-ai-overlay").prop("hidden", false);
		$("#ur-ai-drawer").prop("hidden", false);
		// Let the browser paint the closed state first, so the slide plays.
		setTimeout(function () {
			$("#ur-ai-drawer").addClass("is-open");
			$("#ur-ai-close").trigger("focus");
		}, 20);
	}

	function closeDrawer() {
		$("#ur-ai-drawer").removeClass("is-open");
		setTimeout(function () {
			$("#ur-ai-drawer, #ur-ai-overlay").prop("hidden", true);
		}, 250);
		$("#ur-ai-open").trigger("focus");
	}

	$(document).on("click", "#ur-ai-open", openDrawer);
	$(document).on("click", "#ur-ai-close, #ur-ai-overlay", closeDrawer);
	$(document).on("keydown", function (event) {
		if ("Escape" === event.key && $("#ur-ai-drawer").hasClass("is-open")) {
			closeDrawer();
		}
	});

	$(document).on("click", ".ur-ai-apps__tab", function () {
		selectApp($(this).data("app"));
	});

	$(document).on("change", "#ur-ai-consent", function () {
		$("#ur-ai-create").prop("disabled", !this.checked);
	});

	$(document).on("click", "#ur-ai-manual-toggle", function () {
		$("#ur-ai-manual").prop("hidden", function (index, hidden) {
			return !hidden;
		});
	});

	$(document).on("click", "#ur-ai-copy-prompt", function () {
		copyText($("#ur-ai-prompt").text(), this);
	});

	$(document).on("click", "#ur-ai-copy-pass", function () {
		copyText($(this).data("password"), this);
	});

	$(document).on("click", ".ur-ai-copy", function () {
		copyText($(this).data("copy"), this);
	});

	$(document).on("click", "#ur-ai-check", function () {
		var button = $(this).prop("disabled", true);

		post("ur_ai_connect_check")
			.done(function (response) {
				if (response.success) {
					$("#ur-ai-checks").html(response.data.checks);
					$("#ur-ai-last, #ur-ai-last-card").text(response.data.last);
				}
			})
			.always(function () {
				button.prop("disabled", false);
			});
	});

	$(document).on("click", "#ur-ai-create", function () {
		var button = $(this).prop("disabled", true);
		var label = button.text();

		button.text(i18n.working);
		notice("");

		post("ur_ai_connect_create", { app: apps[current].label })
			.done(function (response) {
				if (response.success) {
					showResult(response.data);
					$("#ur-ai-checks").html(response.data.checks);
				} else {
					notice(message(response));
				}
			})
			.fail(function (xhr) {
				notice(message(xhr.responseJSON));
			})
			.always(function () {
				// The consent covers one password. Ask again for the next one.
				button.text(label).prop("disabled", true);
				$("#ur-ai-consent").prop("checked", false);
			});
	});

	$(document).on("click", "#ur-ai-revoke", function () {
		if (!window.confirm(i18n.confirm)) {
			return;
		}

		post("ur_ai_connect_revoke").done(function (response) {
			if (response.success) {
				$("#ur-ai-checks").html(response.data.checks);
				selectApp(current);
				notice(i18n.removed);
			}
		});
	});
})(jQuery);
