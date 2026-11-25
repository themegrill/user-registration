/**
 * The Connect an AI Assistant card on Settings, Advanced, AI Integration.
 *
 * Runs the readiness check, makes the AI user and its password in one click, and shows what each AI app needs.
 */
(function ($) {
	"use strict";

	var cfg = window.urAiConnect || {};
	var i18n = cfg.i18n || {};

	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, security: cfg.nonce }, data || {}));
	}

	function message(response) {
		return response && response.data && response.data.message ? response.data.message : i18n.failed;
	}

	function copyText(text, button) {
		var done = function () {
			var label = $(button).text();
			$(button).text(i18n.copied);
			setTimeout(function () {
				$(button).text(label === i18n.copied ? i18n.copy : label);
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

	function showResult(data) {
		var box = $("#ur-ai-result").empty().prop("hidden", false);
		var order = ["claude", "cursor", "codex", "prompt"];
		var tabs = $('<div class="ur-ai-connect__tabs" role="tablist">');
		var pre = $("<pre>");
		var current = "claude";

		function select(key) {
			current = key;
			pre.text(data.snippets[key]);
			tabs.find("button").each(function () {
				$(this).toggleClass("is-active", $(this).data("key") === key);
			});
		}

		box.append($("<p>").append($("<strong>").text(i18n.user + ": "), document.createTextNode(data.login)));
		box.append(
			$("<p>").append(
				$("<strong>").text(i18n.password + ": "),
				$('<span class="ur-ai-connect__secret">').text(data.password),
				" ",
				$('<button type="button" class="button button-small">').text(i18n.copy).on("click", function () {
					copyText(data.password.replace(/\s+/g, ""), this);
				})
			)
		);
		box.append($("<p>").append($("<strong>").text(i18n.readyText)));

		order.forEach(function (key) {
			$('<button type="button" class="button">')
				.text(i18n.tabs[key])
				.data("key", key)
				.on("click", function () {
					select(key);
				})
				.appendTo(tabs);
		});

		box.append(tabs, pre);
		box.append(
			$('<button type="button" class="button button-primary">').text(i18n.copy).on("click", function () {
				copyText(data.snippets[current], this);
			})
		);
		select("claude");
	}

	$(document).on("click", ".ur-ai-copy", function () {
		copyText($(this).data("copy"), this);
	});

	$(document).on("click", "#ur-ai-check", function () {
		var button = $(this).prop("disabled", true);

		post("ur_ai_connect_check")
			.done(function (response) {
				if (response.success) {
					$("#ur-ai-checks").html(response.data.checks);
					$("#ur-ai-last").text(response.data.last);
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

		post("ur_ai_connect_create", { app: $("#ur-ai-app").val() })
			.done(function (response) {
				if (response.success) {
					showResult(response.data);
					$("#ur-ai-checks").html(response.data.checks);
				} else {
					$("#ur-ai-result").prop("hidden", false).empty().append($("<p>").text(message(response)));
				}
			})
			.fail(function (xhr) {
				$("#ur-ai-result").prop("hidden", false).empty().append($("<p>").text(message(xhr.responseJSON)));
			})
			.always(function () {
				button.prop("disabled", false).text(label);
			});
	});

	$(document).on("click", "#ur-ai-revoke", function () {
		if (!window.confirm(i18n.confirm)) {
			return;
		}

		post("ur_ai_connect_revoke").done(function (response) {
			if (response.success) {
				$("#ur-ai-checks").html(response.data.checks);
				$("#ur-ai-result").prop("hidden", false).empty().append($("<p>").text(i18n.removed));
			}
		});
	});
})(jQuery);
