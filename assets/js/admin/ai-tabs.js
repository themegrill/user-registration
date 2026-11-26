/**
 * Tabs for the AI Integration settings: the page as it was (the switch and the access switches) on the first tab, and
 * the Connect content on the second. The cards are the ones the page already draws; this only shows one tab at a time.
 * The page is still one form, so Save Changes saves everything.
 */
(function ($) {
	"use strict";

	var cfg = window.urAiTabs || {};

	$(function () {
		var box = $(".user-registration-options-container").first();
		var cards = box.children(".user-registration-card");
		var body = $(".ur-ai-connect-content");

		if (!cards.length || !body.length) {
			box.addClass("ur-ai-tabs-ready");
			return;
		}

		// The Connect content (the checks, connecting an app, the questions to try) is a card of its own, the second tab. It is the same markup, moved, so everything in it keeps working.
		// No card title: the tab already names it, and the panel inside has its own heading.
		var pane = $(
			'<div class="user-registration-card ur-mt-4 ur-border-0 ur-ai-connect-pane">' +
				'<div class="pt-0 pb-0 user-registration-card__body"></div>' +
				"</div>"
		);

		pane.find(".user-registration-card__body").append(body);
		pane.insertAfter(cards.last());

		// The apps that are connected already get a card of their own under it. It stays out of sight until there is one.
		var activeSection = body.find(".ur-ai-active");
		var activePane = $();

		if (activeSection.length) {
			activePane = $(
				'<div class="user-registration-card ur-mt-4 ur-border-0 ur-ai-active-pane">' +
					'<div class="user-registration-card__header ur-border-0"><div class="user-registration-card__header-wrapper"><h3 class="user-registration-card__title"></h3></div></div>' +
					'<div class="pt-0 pb-0 user-registration-card__body"></div>' +
					"</div>"
			);

			activePane.find(".user-registration-card__title").text(cfg.activeLabel);
			activePane.find(".user-registration-card__body").append(activeSection);
			activePane.insertAfter(pane);

			var syncActive = function () {
				activePane.toggleClass("ur-ai-active-pane--empty", !$.trim($("#ur-ai-connections").html()));
			};

			syncActive();
			$(document).on("ur-ai-connections-changed", syncActive);
		}

		// Tab one is every card the page already had. Tab two is the Connect card and the card of active connections.
		var groups = [cards, pane.add(activePane)];
		var names = [(cfg.tabs || [])[0], cfg.connectLabel];
		var nav = $('<div class="urcr-tabs" role="tablist">');
		var active = 0;

		names.forEach(function (name, index) {
			$('<button type="button" role="tab" class="urcr-tab">').text(name).attr("data-tab", index).appendTo(nav);
		});

		nav.insertBefore(cards.first());

		// Connecting needs the master switch on, so that tab waits for the switch.
		function switchOn() {
			var field = $("#" + cfg.switchId);

			return !field.length || field.is(":checked");
		}

		function paint() {
			var on = switchOn();

			groups.forEach(function (group, index) {
				group.toggleClass("ur-ai-pane--off", index !== active);
			});
			nav.children().each(function (index) {
				var waiting = index > 0 && !on;

				$(this).toggleClass("urcr-tab-active", index === active).toggleClass("is-disabled", waiting).attr({ "aria-selected": index === active ? "true" : "false", "aria-disabled": waiting ? "true" : "false", title: waiting ? cfg.needsOn : "" });
			});
		}

		function show(index) {
			if (index > 0 && !switchOn()) {
				return;
			}

			active = index;
			paint();

			try {
				window.history.replaceState(null, "", "#ai-tab=" + index);
			} catch (e) {
				// The tab still works without the address remembering it.
			}
		}

		nav.on("click", ".urcr-tab", function () {
			show(parseInt($(this).attr("data-tab"), 10));
		});

		$(document).on("change", "#" + cfg.switchId, function () {
			if (!switchOn()) {
				active = 0;
			}

			paint();
		});

		var wanted = /ai-tab=(\d+)/.exec(window.location.hash);
		var start = wanted ? parseInt(wanted[1], 10) : 0;

		active = start > 0 && start < names.length && switchOn() ? start : 0;
		paint();
		box.addClass("ur-ai-tabs-ready");
	});
})(jQuery);
