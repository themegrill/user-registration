(function ($) {
	"use strict";

	if (typeof window.urEmailHealthData === "undefined") {
		return;
	}

	const rootEl = document.getElementById("ur-email-health-root");
	if (!rootEl) {
		return;
	}

	const config = window.urEmailHealthData;
	const i18n = config.i18n || {};

	const state = {
		report: config.initial_report || {},
		testEmail: (config.initial_report && config.initial_report.admin_email) || "",
		waitingForExternalSetup: false,
		editingFromInline: false,
		isWorking: false,
		isSendingTest: false,
		isConfirmingTest: false,
		isRechecking: false,
		ehTestState: null, // null | 'sent' | 'failed' | 'retest'
		testError: "",
		lastSentTo: "",
		testSentWhileDisabled: false
	};

	function esc(val) {
		return String(val === undefined || val === null ? "" : val)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	function sprintf(template) {
		const args = Array.prototype.slice.call(arguments, 1);
		let idx = 0;
		return String(template || "")
			.replace(/%(\d+)\$[sd]/g, function (_, pos) {
				const argIndex = parseInt(pos, 10) - 1;
				return args[argIndex] !== undefined ? args[argIndex] : "";
			})
			.replace(/%[sd]/g, function () {
				const val = args[idx++];
				return val !== undefined ? val : "";
			});
	}

	function cleanErr(msg) {
		return String(msg || "")
			.trim()
			.replace(/\.+$/, "");
	}

	function isValidEmail(val) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(val || "").trim());
	}

	function isValidEmailList(val) {
		const parts = String(val || "")
			.split(",")
			.map(function (item) {
				return item.trim();
			});
		if (!parts.length || parts.some(function (item) { return item === ""; })) {
			return false;
		}
		return parts.every(isValidEmail);
	}

	function ensureToastStack() {
		let stack = document.getElementById("ur-eh-toast-stack");
		if (!stack) {
			stack = document.createElement("div");
			stack.id = "ur-eh-toast-stack";
			stack.className = "ur-eh-toast-stack";
			stack.setAttribute("aria-live", "polite");
			document.body.appendChild(stack);
		}
		return stack;
	}

	function showToast(msg, isError) {
		const stack = ensureToastStack();
		const item = document.createElement("div");
		item.className = "ur-eh-toast-item" + (isError ? " is-error" : "");
		item.textContent = msg;
		stack.appendChild(item);
		setTimeout(function () {
			item.style.opacity = "0";
			setTimeout(function () {
				if (item.parentNode) {
					item.parentNode.removeChild(item);
				}
			}, 200);
		}, 3000);
	}

	function focusHeaderVerdict() {
		const el = document.getElementById("ur-eh-header-title");
		if (el) {
			el.focus();
		}
	}

	function syncSidebarDot() {
		const dotEl = document.getElementById("ur-eh-sidebar-dot");
		if (!dotEl) {
			return;
		}
		const report = state.report || {};
		const smtp = report.smtp || {};
		const failures = report.recent_failures || {};
		const confirmation = report.email_confirmation || {};
		const incoming = report.incoming_issue || "";
		const conflicts = Array.isArray(smtp.conflicting_plugins) ? smtp.conflicting_plugins : [];

		const hasCritical =
			Boolean(report.emails_disabled) ||
			(Boolean(confirmation.disabled) && Boolean(confirmation.form_requires_confirmation)) ||
			smtp.status === "token-expired" ||
			Boolean(smtp.probe_error) ||
			Number(failures.count || 0) > 0 ||
			Boolean(failures.last_error) ||
			incoming === "failed";

		const hasWarning =
			Boolean(confirmation.disabled) ||
			smtp.status === "unconfigured" ||
			smtp.status === "other-smtp-unconfigured" ||
			conflicts.length > 0 ||
			Boolean(incoming);

		dotEl.className = "ur-eh-sidebar-dot";
		if (hasCritical) {
			dotEl.classList.add("is-critical");
			dotEl.innerHTML =
				'<span class="ur-eh-dot-circle" aria-hidden="true"></span><span class="screen-reader-text">' +
				esc(i18n.sr_action_needed) +
				"</span>";
		} else if (hasWarning) {
			dotEl.classList.add("is-warning");
			dotEl.innerHTML =
				'<span class="ur-eh-dot-circle" aria-hidden="true"></span><span class="screen-reader-text">' +
				esc(i18n.sr_check_recommended) +
				"</span>";
		} else {
			dotEl.innerHTML = "";
		}
	}

	function buildSmtpActionBtn() {
		const smtp = state.report.smtp || {};
		if (smtp.status === "token-expired") {
			return (
				'<button type="button" class="ur-eh-btn-primary" data-action="connect-smtp">' +
				esc(i18n.reconnect_btn) +
				"</button>"
			);
		}
		if (smtp.status === "unconfigured") {
			return (
				'<button type="button" class="ur-eh-btn-primary" data-action="connect-smtp">' +
				esc(i18n.configure_smtp_btn) +
				"</button>"
			);
		}
		if (smtp.status === "other-smtp-unconfigured") {
			if (!smtp.other_smtp_settings_url) {
				return "";
			}
			return (
				'<a class="ur-eh-btn-primary" href="' +
				esc(smtp.other_smtp_settings_url) +
				'" target="_blank" rel="noopener noreferrer">' +
				esc(sprintf(i18n.configure_other_btn, smtp.other_smtp_name || "SMTP")) +
				"</a>"
			);
		}

		const canInstallOrActivate = smtp.smart_smtp_installed
			? smtp.can_activate_plugins
			: smtp.can_install_plugins && smtp.can_activate_plugins;

		if (!canInstallOrActivate) {
			return "";
		}

		const idleLabel = i18n.setup_smart_smtp_btn || i18n.setup_smtp_link;
		const workingLabel = smtp.smart_smtp_installed ? i18n.activating_btn : i18n.installing_btn;

		return (
			'<button type="button" class="ur-eh-btn-primary" data-action="open-smtp-confirm" ' +
			(state.isWorking ? "disabled" : "") +
			">" +
			(state.isWorking
				? '<span class="ur-eh-spinner" aria-hidden="true"></span><span>' + esc(workingLabel) + "</span>"
				: esc(idleLabel)) +
			"</button>"
		);
	}

	function renderShellOnce() {
		if (document.getElementById("ur-eh-card")) {
			return;
		}
		rootEl.innerHTML =
			'<div class="ur-eh-card" id="ur-eh-card">' +
			'  <div class="ur-eh-header" id="ur-eh-header-slot" role="status" aria-live="polite"></div>' +
			'  <div id="ur-eh-tips-slot"></div>' +
			'  <ul class="ur-eh-list" id="ur-eh-list-slot"></ul>' +
			'  <div class="ur-eh-test-footer">' +
			'    <div class="ur-eh-test-title">' +
			esc(i18n.test_box_title) +
			"</div>" +
			'    <div class="ur-eh-test-row">' +
			'      <label for="ur-eh-test-input" class="screen-reader-text">' +
			esc(i18n.test_box_title) +
			"</label>" +
			'      <input type="email" class="ur-eh-input is-test-input" id="ur-eh-test-input" value="' +
			esc(state.testEmail) +
			'" />' +
			'      <button type="button" class="ur-eh-btn-primary" id="ur-eh-send-btn" style="height:38px;">' +
			esc(i18n.send_test_email) +
			"</button>" +
			"    </div>" +
			'    <div id="ur-eh-test-feedback-slot" aria-live="polite"></div>' +
			"  </div>" +
			"</div>";

		const testInput = document.getElementById("ur-eh-test-input");
		const sendBtn = document.getElementById("ur-eh-send-btn");

		testInput.addEventListener("input", function (e) {
			state.testEmail = e.target.value;
		});
		testInput.addEventListener("keydown", function (e) {
			if (e.key === "Enter") {
				e.preventDefault();
				sendTestEmail();
			}
		});
		sendBtn.addEventListener("click", function () {
			sendTestEmail();
		});
	}

	function render() {
		renderShellOnce();

		const report = state.report || {};
		const smtp = report.smtp || {};
		const confirmation = report.email_confirmation || {};
		const fromInfo = report.from_address || {};
		const failures = report.recent_failures || {};
		const incomingIssue = report.incoming_issue || "";
		const siteDomain = report.site_domain || "";
		const conflicts = Array.isArray(smtp.conflicting_plugins) ? smtp.conflicting_plugins : [];

		const smtpReady = smtp.status === "connected" || smtp.status === "other-smtp";
		const recentFailedCount = Number(failures.count || 0);
		const lastError = failures.last_error || "";
		const hasFailureRecord = recentFailedCount > 0 || Boolean(lastError) || incomingIssue === "failed";
		const hasDeliveryEvidence = hasFailureRecord || Boolean(incomingIssue);
		const forceFromBySmartSmtp = smtp.status === "connected" && Boolean(smtp.force_from);
		const forceFromByOther = smtp.status === "other-smtp" && Boolean(smtp.force_from);
		const fromOk = smtpReady || Boolean(fromInfo.matches_domain) || !hasDeliveryEvidence;

		const issues = [];
		const quietRows = [];

		// 1. Registration Emails
		if (report.emails_disabled) {
			issues.push({
				type: "err",
				title: i18n.all_disabled_title,
				desc: i18n.all_disabled_desc,
				right:
					'<button type="button" class="ur-eh-btn-primary" data-action="enable-emails" ' +
					(state.isWorking ? "disabled" : "") +
					">" +
					esc(i18n.turn_emails_on) +
					"</button>"
			});
		} else {
			quietRows.push({
				icon: "ok",
				title: i18n.reg_emails_title,
				value: i18n.enabled
			});
		}

		// 2. Email Notifications
		if (confirmation.disabled) {
			let confirmDesc = i18n.confirm_off_default_desc;
			if (confirmation.form_requires_confirmation) {
				confirmDesc =
					Number(confirmation.form_count || 0) === 1 && confirmation.first_form_title
						? sprintf(i18n.confirm_off_form_desc, confirmation.first_form_title)
						: i18n.confirm_off_multi_desc;
			}
			issues.push({
				type: confirmation.form_requires_confirmation ? "err" : "warn",
				title: i18n.confirm_off_title,
				desc: confirmDesc,
				right:
					'<button type="button" class="ur-eh-btn-primary" data-action="enable-template" ' +
					(state.isWorking ? "disabled" : "") +
					">" +
					esc(i18n.turn_confirm_on) +
					"</button>"
			});
		} else {
			quietRows.push({
				icon: "ok",
				title: i18n.notifications_title,
				value: i18n.all_active
			});
		}

		// 3. Email Delivery (SMTP)
		if (smtp.status === "token-expired") {
			issues.push({
				type: "err",
				title: i18n.reconnect_title,
				desc: lastError ? sprintf(i18n.reconnect_desc_err, cleanErr(lastError)) : i18n.reconnect_desc,
				right: buildSmtpActionBtn()
			});
		} else if (smtp.status === "unconfigured") {
			if (state.waitingForExternalSetup) {
				issues.push({
					type: "info",
					title: i18n.finish_tab_title,
					desc: i18n.finish_tab_desc,
					right:
						'<button type="button" class="ur-eh-btn-primary" data-action="recheck-connected">' +
						esc(i18n.recheck) +
						"</button>"
				});
			} else {
				issues.push({
					type: hasFailureRecord ? "err" : "warn",
					title: i18n.finish_setup_title,
					desc: lastError ? sprintf(i18n.finish_setup_desc_err, cleanErr(lastError)) : i18n.finish_setup_desc,
					right: buildSmtpActionBtn()
				});
			}
		} else if (smtp.status === "other-smtp-unconfigured") {
			const otherName = smtp.other_smtp_name || "SMTP";
			issues.push({
				type: hasFailureRecord ? "err" : "warn",
				title: sprintf(i18n.other_unconfigured_title, otherName),
				desc: lastError
					? sprintf(i18n.other_unconfigured_desc_err, cleanErr(lastError), otherName)
					: sprintf(i18n.other_unconfigured_desc, otherName, otherName),
				right: buildSmtpActionBtn()
			});
		} else if (smtp.status === "connected") {
			const providerLabel = smtp.provider_label || "SmartSMTP";
			if (hasFailureRecord) {
				const cta = smtp.logs_url
					? '<a class="ur-eh-btn-outline" href="' +
						esc(smtp.logs_url) +
						'" target="_blank" rel="noopener noreferrer">' +
						esc(i18n.view_smartsmtp_logs) +
						"</a>"
					: "";
				issues.push({
					type: "err",
					title: sprintf(i18n.smtp_send_failed_title, providerLabel),
					desc: lastError
						? sprintf(i18n.smtp_send_failed_desc, cleanErr(lastError), providerLabel)
						: sprintf(i18n.smtp_send_failed_generic_desc, providerLabel),
					right: cta
				});
			} else {
				quietRows.push({
					icon: "ok",
					title: i18n.smtp_title,
					value: providerLabel
				});
			}
		} else if (smtp.status === "other-smtp") {
			const otherName = smtp.other_smtp_name || "SMTP";
			if (hasFailureRecord) {
				const settingsCta = smtp.other_smtp_settings_url
					? '<a class="ur-eh-btn-outline" href="' +
						esc(smtp.other_smtp_settings_url) +
						'" target="_blank" rel="noopener noreferrer">' +
						esc(sprintf(i18n.configure_other_btn, otherName)) +
						"</a>"
					: "";
				issues.push({
					type: "err",
					title: sprintf(i18n.smtp_send_failed_title, otherName),
					desc: lastError
						? sprintf(i18n.smtp_send_failed_desc, cleanErr(lastError), otherName)
						: sprintf(i18n.smtp_send_failed_generic_desc, otherName),
					right: settingsCta
				});
			} else {
				quietRows.push({
					icon: "ok",
					title: i18n.smtp_title,
					value: sprintf(i18n.managed_by, otherName)
				});
			}
		} else if (smtp.probe_error) {
			issues.push({
				type: "err",
				title: i18n.probe_error_title,
				desc: sprintf(
					i18n.probe_error_desc,
					smtp.probe_error_owner || "Custom mail handler",
					cleanErr(smtp.probe_error)
				),
				right: buildSmtpActionBtn()
			});
		} else if (hasDeliveryEvidence) {
			const isErr = hasFailureRecord;
			let title = i18n.unauth_server_title;
			if (recentFailedCount > 0) {
				title = sprintf(
					recentFailedCount === 1 ? i18n.recent_failure_single : i18n.recent_failures_title,
					recentFailedCount
				);
			} else if (lastError || incomingIssue === "failed") {
				title = i18n.server_failed_title;
			}
			issues.push({
				type: isErr ? "err" : "warn",
				title: title,
				desc: lastError ? sprintf(i18n.server_failed_desc, cleanErr(lastError)) : i18n.unauth_server_desc,
				right: buildSmtpActionBtn()
			});
		} else {
			const canInstallOrActivate = smtp.smart_smtp_installed
				? smtp.can_activate_plugins
				: smtp.can_install_plugins && smtp.can_activate_plugins;

			let valueHtml = esc(i18n.server_default_label);
			if (canInstallOrActivate) {
				valueHtml +=
					' · <button type="button" class="ur-eh-link-btn" data-action="open-smtp-confirm">' +
					esc(i18n.setup_smtp_link) +
					"</button>";
			}

			quietRows.push({
				icon: "neutral",
				srPrefix: i18n.sr_info,
				title: i18n.smtp_title,
				value: valueHtml,
				rawValue: true
			});
		}

		// 3b. Conflicting SMTP plugins warning
		if (conflicts.length > 0) {
			const primaryName =
				smtp.other_smtp_name || (smtp.provider_label ? smtp.provider_label : "SmartSMTP");
			const pluginsCta =
				smtp.can_activate_plugins && smtp.plugins_url
					? '<a class="ur-eh-btn-outline" href="' +
						esc(smtp.plugins_url) +
						'">' +
						esc(i18n.manage_plugins_btn) +
						"</a>"
					: "";
			issues.push({
				type: "warn",
				title: i18n.smtp_conflict_title,
				desc: sprintf(i18n.smtp_conflict_desc, primaryName, conflicts.join(", ")),
				right: pluginsCta
			});
		}

		// 4. Sender ("From") Address
		if (forceFromBySmartSmtp) {
			const acct = smtp.account_email || fromInfo.first || report.admin_email || "";
			quietRows.push({
				icon: "ok",
				title: i18n.from_title,
				value: sprintf(i18n.from_managed_smartsmtp, acct)
			});
		} else if (forceFromByOther) {
			quietRows.push({
				icon: "ok",
				title: i18n.from_title,
				value: sprintf(i18n.managed_by, smtp.other_smtp_name || "SMTP")
			});
		} else if (fromOk) {
			quietRows.push({
				icon: "ok",
				title: i18n.from_title,
				value: fromInfo.first || report.admin_email || ""
			});
		} else {
			const displayFrom = fromInfo.first || i18n.from_not_set;
			issues.push({
				type: "warn",
				title: i18n.from_mismatch_title,
				desc: sprintf(i18n.from_mismatch_desc, displayFrom, siteDomain),
				right: state.editingFromInline
					? ""
					: '<button type="button" class="ur-eh-btn-outline" data-action="open-from-editor">' +
						esc(i18n.update_address) +
						"</button>",
				extra: state.editingFromInline
					? '<div class="ur-eh-inline-editor">' +
						'<div class="ur-eh-inline-editor-hint">' +
						sprintf(i18n.from_editor_hint, esc(siteDomain)) +
						"</div>" +
						'<div class="ur-eh-inline-editor-row">' +
						'<input type="email" multiple class="ur-eh-input is-inline-input" id="ur-eh-inline-from-input" value="' +
						esc(fromInfo.suggested || "") +
						'" />' +
						'<button type="button" class="ur-eh-btn-primary" style="height:34px;" data-action="save-inline-from" ' +
						(state.isWorking ? "disabled" : "") +
						">" +
						esc(i18n.save) +
						"</button>" +
						'<button type="button" class="ur-eh-btn-subtle" style="height:34px;" data-action="cancel-inline-from">' +
						esc(i18n.cancel) +
						"</button>" +
						"</div>" +
						"</div>"
					: ""
			});
		}

		// Header Verdict
		const problemCount = issues.filter(function (item) {
			return item.type === "err";
		}).length;
		const recCount = issues.filter(function (item) {
			return item.type !== "err";
		}).length;
		const hasIncomingWhileHealthy =
			issues.length === 0 && (incomingIssue === "spam" || incomingIssue === "not_received");
		const isUnverifiedServerMail =
			issues.length === 0 && !smtpReady && !Boolean(report.test_email_confirmed) && !incomingIssue;

		const ringClass =
			issues.length === 0
				? hasIncomingWhileHealthy || isUnverifiedServerMail
					? "neutral"
					: "ok"
				: problemCount > 0
					? "err"
					: "warn";

		const ringIcon =
			ringClass === "ok"
				? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>'
				: ringClass === "neutral"
					? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r="1" fill="currentColor" stroke="none"/></svg>'
					: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13.5"/><circle cx="12" cy="17" r="1.1" fill="currentColor" stroke="none"/></svg>';

		let headerTitle = "";
		let headerSub = "";

		if (issues.length === 0) {
			if (incomingIssue === "spam") {
				headerTitle = i18n.verdict_spam_title;
				headerSub = i18n.verdict_spam_sub;
			} else if (incomingIssue === "not_received") {
				headerTitle = i18n.verdict_not_received_title;
				headerSub = i18n.verdict_not_received_sub;
			} else if (isUnverifiedServerMail) {
				headerTitle = i18n.verdict_unverified_title;
				headerSub = i18n.verdict_unverified_sub;
			} else {
				headerTitle = i18n.verdict_ok_title;
				if (smtpReady) {
					const activeProvider =
						smtp.status === "other-smtp" ? smtp.other_smtp_name : smtp.provider_label || "SmartSMTP";
					headerSub = sprintf(i18n.verdict_ok_smtp_sub, activeProvider);
				} else {
					headerSub = i18n.verdict_ok_server_sub;
				}
			}
		} else {
			const parts = [];
			if (problemCount > 0) {
				parts.push(
					sprintf(problemCount === 1 ? i18n.problem_singular : i18n.problem_plural, problemCount)
				);
			}
			if (recCount > 0) {
				parts.push(sprintf(recCount === 1 ? i18n.rec_singular : i18n.rec_plural, recCount));
			}
			headerTitle = parts.join(" · ");
			headerSub =
				incomingIssue === "spam"
					? i18n.header_sub_spam
					: incomingIssue === "not_received"
						? i18n.header_sub_not_received
						: incomingIssue === "failed"
							? i18n.header_sub_failed
							: i18n.header_sub_default;
		}

		document.getElementById("ur-eh-header-slot").innerHTML =
			'<div class="ur-eh-header-left">' +
			'  <span class="ur-eh-status-ring ' +
			ringClass +
			'">' +
			ringIcon +
			"</span>" +
			"  <div>" +
			'    <h4 class="ur-eh-header-title" id="ur-eh-header-title" tabindex="-1">' +
			esc(headerTitle) +
			"</h4>" +
			'    <p class="ur-eh-header-sub">' +
			esc(headerSub) +
			"</p>" +
			"  </div>" +
			"</div>" +
			'<button type="button" class="ur-eh-btn-ghost" id="ur-eh-recheck-btn" ' +
			(state.isRechecking ? "disabled" : "") +
			">" +
			esc(i18n.recheck) +
			"</button>";

		// Contextual Troubleshooting Tips
		const tipsSlot = document.getElementById("ur-eh-tips-slot");
		if (issues.length === 0 && smtpReady && (incomingIssue === "spam" || incomingIssue === "not_received")) {
			const isSpam = incomingIssue === "spam";
			const logsCta =
				smtp.status === "connected" && smtp.logs_url
					? '<a class="ur-eh-btn-outline" href="' +
						esc(smtp.logs_url) +
						'" target="_blank" rel="noopener noreferrer">' +
						esc(i18n.view_smartsmtp_logs) +
						"</a>"
					: "";

			const spamTips = ["<li>" + i18n.tip_spf_dkim + "</li>"];
			if (!forceFromBySmartSmtp && !forceFromByOther) {
				spamTips.push("<li>" + i18n.tip_match_from + "</li>");
			}
			spamTips.push("<li>" + i18n.tip_new_domain + "</li>");

			const notReceivedTips = [
				"<li>" +
					(smtp.status === "connected"
						? i18n.tip_logs_smartsmtp
						: sprintf(i18n.tip_logs_other, esc(smtp.other_smtp_name || "SMTP"))) +
					"</li>",
				"<li>" + i18n.tip_wait_queue + "</li>",
				"<li>" + sprintf(i18n.tip_double_check_recipient, esc(state.testEmail)) + "</li>"
			];

			tipsSlot.innerHTML =
				'<div class="ur-eh-tips-section">' +
				'  <div class="ur-eh-tips-title">' +
				esc(isSpam ? i18n.tips_spam_title : i18n.tips_not_received_title) +
				"</div>" +
				'  <ul class="ur-eh-tips-list">' +
				(isSpam ? spamTips : notReceivedTips).join("") +
				"</ul>" +
				'  <div class="ur-eh-tips-actions">' +
				logsCta +
				'<button type="button" class="ur-eh-btn-ghost" data-action="clear-context">' +
				esc(i18n.dismiss) +
				"</button>" +
				"  </div>" +
				"</div>";
		} else {
			tipsSlot.innerHTML = "";
		}

		// Unified Diagnostic List
		const issueHtml = issues
			.map(function (item) {
				const srLabel =
					item.type === "err"
						? i18n.sr_problem
						: item.type === "info"
							? i18n.sr_in_progress
							: i18n.sr_recommendation;
				return (
					'<li class="ur-eh-item">' +
					'  <div class="ur-eh-item-main">' +
					'    <span class="ur-eh-dot ' +
					item.type +
					'" aria-hidden="true">' +
					(item.type === "info" ? "↗" : "!") +
					"</span>" +
					'    <div class="ur-eh-item-body">' +
					'      <p class="ur-eh-item-title"><span class="screen-reader-text">' +
					esc(srLabel) +
					"</span>" +
					esc(item.title) +
					"</p>" +
					'      <p class="ur-eh-item-desc">' +
					esc(item.desc) +
					"</p>" +
					(item.extra || "") +
					"    </div>" +
					"  </div>" +
					(item.right ? '<div class="ur-eh-item-right">' + item.right + "</div>" : "") +
					"</li>"
				);
			})
			.join("");

		const quietHtml = quietRows
			.map(function (row) {
				const srPrefix = row.srPrefix || (row.icon === "ok" ? i18n.sr_passed : i18n.sr_info);
				const valHtml = row.rawValue ? row.value : esc(row.value);
				if (row.extra) {
					return (
						'<li class="ur-eh-item is-expandable">' +
						'  <div class="ur-eh-expandable-head">' +
						'    <div class="ur-eh-item-main" style="align-items:center;">' +
						'      <span class="ur-eh-dot ' +
						row.icon +
						'" style="margin-top:0;" aria-hidden="true">' +
						(row.icon === "ok" ? "✓" : "i") +
						"</span>" +
						'      <p class="ur-eh-item-title" style="font-weight:500;color:#374151;"><span class="screen-reader-text">' +
						esc(srPrefix) +
						"</span>" +
						esc(row.title) +
						"</p>" +
						"    </div>" +
						'    <div class="ur-eh-passed-value">' +
						valHtml +
						"</div>" +
						"  </div>" +
						row.extra +
						"</li>"
					);
				}
				return (
					'<li class="ur-eh-item is-quiet">' +
					'  <div class="ur-eh-item-main">' +
					'    <span class="ur-eh-dot ' +
					row.icon +
					'" aria-hidden="true">' +
					(row.icon === "ok" ? "✓" : "i") +
					"</span>" +
					'    <p class="ur-eh-item-title"><span class="screen-reader-text">' +
					esc(srPrefix) +
					"</span>" +
					esc(row.title) +
					"</p>" +
					"  </div>" +
					'  <div class="ur-eh-passed-value">' +
					valHtml +
					"</div>" +
					"</li>"
				);
			})
			.join("");

		document.getElementById("ur-eh-list-slot").innerHTML = issueHtml + quietHtml;

		// Test Email Footer Button & Feedback
		const sendBtn = document.getElementById("ur-eh-send-btn");
		if (sendBtn) {
			sendBtn.disabled = state.isSendingTest;
			sendBtn.innerHTML = state.isSendingTest
				? '<span class="ur-eh-spinner" aria-hidden="true"></span><span>' + esc(i18n.sending) + "</span>"
				: esc(i18n.send_test_email);
		}

		const fbSlot = document.getElementById("ur-eh-test-feedback-slot");
		if (!state.ehTestState) {
			fbSlot.innerHTML = "";
		} else if (state.ehTestState === "retest") {
			const providerName = smtp.provider_label || "SmartSMTP";
			fbSlot.innerHTML =
				'<div class="ur-eh-fb-bar is-retest" role="status">' +
				"  <span>" +
				sprintf(i18n.retest_prompt, esc(providerName)) +
				"</span>" +
				"</div>";
		} else if (state.ehTestState === "sent") {
			const disabledWarnHtml = state.testSentWhileDisabled
				? '<div class="ur-eh-fb-bar is-warn" role="status"><span>' +
					esc(i18n.emails_disabled_sent_warn) +
					"</span></div>"
				: "";
			fbSlot.innerHTML =
				'<div class="ur-eh-fb-bar" role="status">' +
				"  <span>" +
				sprintf(i18n.sent_prompt, esc(state.lastSentTo || state.testEmail)) +
				"</span>" +
				'  <div class="ur-eh-fb-actions">' +
				'    <button type="button" class="ur-eh-btn-subtle" data-fb="yes" ' +
				(state.isConfirmingTest ? "disabled" : "") +
				">" +
				esc(i18n.yes_received) +
				"</button>" +
				'    <button type="button" class="ur-eh-btn-subtle" data-fb="not_yet" ' +
				(state.isConfirmingTest ? "disabled" : "") +
				">" +
				esc(i18n.not_yet) +
				"</button>" +
				'    <button type="button" class="ur-eh-btn-subtle" data-fb="spam" ' +
				(state.isConfirmingTest ? "disabled" : "") +
				">" +
				esc(i18n.in_spam) +
				"</button>" +
				"  </div>" +
				"</div>" +
				disabledWarnHtml;
		} else if (state.ehTestState === "failed") {
			fbSlot.innerHTML =
				'<div class="ur-eh-fb-bar is-err" role="alert">' +
				"  <span><strong>" +
				esc(i18n.failed_prefix) +
				"</strong> " +
				esc(state.testError || i18n.failed_fallback) +
				"</span>" +
				"</div>";
		}

		syncSidebarDot();
		bindDynamicHandlers();
	}

	function postAjax(action, payload) {
		return $.ajax({
			url: config.ajax_url,
			type: "POST",
			dataType: "json",
			data: Object.assign({ action: action }, payload || {})
		});
	}

	function refreshStatus(options) {
		const opts = options || {};
		if (state.isRechecking) {
			return;
		}
		state.isRechecking = true;
		render();

		postAjax("user_registration_email_health_status", {
			security: config.nonce,
			issue: state.report.incoming_issue || ""
		})
			.done(function (res) {
				if (res && res.success && res.data && res.data.report) {
					const wasWaiting = state.waitingForExternalSetup;
					state.report = res.data.report;
					const newStatus = (state.report.smtp && state.report.smtp.status) || "none";
					if (wasWaiting && newStatus === "connected") {
						state.waitingForExternalSetup = false;
						state.report.incoming_issue = "";
						state.ehTestState = "retest";
						showToast(
							opts.autoTriggered ? i18n.auto_detected_connected : i18n.connection_verified
						);
						if (!opts.autoTriggered) {
							focusHeaderVerdict();
						}
					} else if (opts.manual) {
						if (wasWaiting && newStatus !== "connected") {
							showToast(i18n.still_not_connected, true);
						} else {
							showToast(i18n.recheck_done);
						}
					}
				}
			})
			.fail(function () {
				if (opts.manual) {
					showToast(i18n.request_failed, true);
				}
			})
			.always(function () {
				state.isRechecking = false;
				render();
			});
	}

	function applyFix(fixAction, extraData) {
		if (state.isWorking) {
			return;
		}
		state.isWorking = true;
		render();

		postAjax(
			"user_registration_email_health_fix",
			Object.assign(
				{
					security: config.nonce,
					fix_action: fixAction,
					issue: state.report.incoming_issue || ""
				},
				extraData || {}
			)
		)
			.done(function (res) {
				if (res && res.success && res.data && res.data.report) {
					state.report = res.data.report;
					if (fixAction === "enable_emails") {
						state.testSentWhileDisabled = false;
						if (state.ehTestState === "failed") {
							state.ehTestState = null;
							state.testError = "";
						}
					}
					if (fixAction === "update_from_address") {
						state.editingFromInline = false;
					}
					if (res.data.message) {
						showToast(res.data.message);
					}
				} else {
					showToast((res && res.data && res.data.message) || i18n.request_failed, true);
				}
			})
			.fail(function () {
				showToast(i18n.request_failed, true);
			})
			.always(function () {
				state.isWorking = false;
				render();
				if (fixAction === "enable_emails" || fixAction === "enable_confirmation") {
					focusHeaderVerdict();
				}
			});
	}

	function installSmartSmtp() {
		if (state.isWorking) {
			return;
		}
		state.isWorking = true;
		render();

		postAjax("user_registration_email_health_install_smart_smtp", {
			security: config.nonce,
			issue: state.report.incoming_issue || ""
		})
			.done(function (res) {
				if (res && res.success && res.data && res.data.report) {
					state.report = res.data.report;
					if (res.data.message) {
						showToast(res.data.message);
					}
				} else {
					showToast((res && res.data && res.data.message) || i18n.request_failed, true);
				}
			})
			.fail(function () {
				showToast(i18n.request_failed, true);
			})
			.always(function () {
				state.isWorking = false;
				render();
				focusHeaderVerdict();
			});
	}

	function openSmtpInstallModal() {
		const smtp = state.report.smtp || {};
		const isInstalled = Boolean(smtp.smart_smtp_installed);
		const title = isInstalled ? i18n.activate_btn : i18n.install_activate_btn;
		const copy = isInstalled ? i18n.activate_smtp_confirm_copy : i18n.setup_smtp_confirm_copy;
		const confirmLabel = isInstalled
			? i18n.activate_modal_confirm_btn || i18n.activate_btn
			: i18n.install_modal_confirm_btn || i18n.install_activate_btn;

		if (typeof window.Swal === "undefined" || typeof window.Swal.fire !== "function") {
			if (window.confirm(title)) {
				installSmartSmtp();
			}
			return;
		}

		window.Swal.fire({
			title: esc(title),
			html: copy,
			customClass: "user-registration-swal2-modal user-registration-swal2-modal--centered ur-eh-swal-modal",
			showCloseButton: true,
			showCancelButton: true,
			showConfirmButton: true,
			confirmButtonText: esc(confirmLabel),
			cancelButtonText: esc(i18n.cancel),
			showLoaderOnConfirm: true,
			allowOutsideClick: function () {
				return !window.Swal.isLoading();
			},
			allowEscapeKey: function () {
				return !window.Swal.isLoading();
			},
			heightAuto: false,
			width: "520px",
			preConfirm: function () {
				return new Promise(function (resolve) {
					postAjax("user_registration_email_health_install_smart_smtp", {
						security: config.nonce,
						issue: state.report.incoming_issue || ""
					})
						.done(function (res) {
							if (res && res.success && res.data && res.data.report) {
								resolve(res);
								return;
							}
							const msg = (res && res.data && res.data.message) || i18n.request_failed;
							window.Swal.showValidationMessage(esc(msg));
							resolve(false);
						})
						.fail(function () {
							window.Swal.showValidationMessage(esc(i18n.request_failed));
							resolve(false);
						});
				});
			}
		}).then(function (result) {
			if (result && result.isConfirmed && result.value && result.value.data && result.value.data.report) {
				state.report = result.value.data.report;
				if (result.value.data.message) {
					showToast(result.value.data.message);
				}
				render();
				focusHeaderVerdict();
			}
		});
	}

	function sendTestEmail() {
		if (state.isSendingTest) {
			return;
		}
		const targetEmail = String(state.testEmail || "").trim();
		if (!isValidEmail(targetEmail)) {
			showToast(i18n.invalid_email, true);
			return;
		}

		state.isSendingTest = true;
		render();

		postAjax("user_registration_send_test_email", {
			nonce: config.test_email_nonce,
			email: targetEmail,
			defer_completion: "1"
		})
			.done(function (res) {
				if (res && res.success) {
					state.lastSentTo = targetEmail;
					state.ehTestState = "sent";
					state.testError = "";
					state.testSentWhileDisabled = Boolean(res.data && res.data.emails_disabled);
					if (res.data && res.data.report) {
						state.report = res.data.report;
					} else {
						refreshStatus({ manual: false });
					}
				} else {
					state.ehTestState = "failed";
					state.testSentWhileDisabled = false;
					state.testError =
						(res && res.data && (res.data.raw_error || res.data.message)) || i18n.failed_fallback;
					if (res && res.data && res.data.report) {
						state.report = res.data.report;
					} else {
						refreshStatus({ manual: false });
					}
				}
			})
			.fail(function () {
				state.ehTestState = "failed";
				state.testSentWhileDisabled = false;
				state.testError = i18n.request_failed;
			})
			.always(function () {
				state.isSendingTest = false;
				render();
			});
	}

	function confirmTestEmailReceived() {
		if (state.isConfirmingTest) {
			return;
		}
		state.isConfirmingTest = true;
		render();

		postAjax("user_registration_confirm_test_email", {
			nonce: config.test_email_nonce
		})
			.done(function (res) {
				if (res && res.success) {
					state.ehTestState = null;
					state.testSentWhileDisabled = false;
					if (res.data && res.data.report) {
						state.report = res.data.report;
					} else {
						state.report.incoming_issue = "";
						if (state.report.recent_failures) {
							state.report.recent_failures.count = 0;
							state.report.recent_failures.last_error = "";
						}
					}
				} else {
					showToast((res && res.data && res.data.message) || i18n.confirm_failed || i18n.request_failed, true);
				}
			})
			.fail(function () {
				showToast(i18n.confirm_failed || i18n.request_failed, true);
			})
			.always(function () {
				state.isConfirmingTest = false;
				render();
			});
	}

	function bindDynamicHandlers() {
		const recheckBtn = document.getElementById("ur-eh-recheck-btn");
		if (recheckBtn) {
			recheckBtn.onclick = function () {
				refreshStatus({ manual: true, autoTriggered: false });
			};
		}

		rootEl.querySelectorAll('[data-action="clear-context"]').forEach(function (el) {
			el.onclick = function () {
				state.report.incoming_issue = "";
				render();
				focusHeaderVerdict();
			};
		});

		rootEl.querySelectorAll('[data-action="open-smtp-confirm"]').forEach(function (el) {
			el.onclick = function () {
				openSmtpInstallModal();
			};
		});

		rootEl.querySelectorAll('[data-action="connect-smtp"]').forEach(function (el) {
			el.onclick = function () {
				const setupUrl =
					(state.report.smtp && state.report.smtp.setup_url) ||
					"admin.php?page=smart-smtp&urm_setup=google_workspace&is_active=true&source=urm#/primary-connection";
				state.waitingForExternalSetup = true;
				window.open(setupUrl, "_blank", "noopener,noreferrer");
				showToast(i18n.opened_smart_smtp);
				render();
				focusHeaderVerdict();
			};
		});

		rootEl.querySelectorAll('[data-action="recheck-connected"]').forEach(function (el) {
			el.onclick = function () {
				refreshStatus({ manual: true, autoTriggered: false });
			};
		});

		rootEl.querySelectorAll('[data-action="enable-emails"]').forEach(function (el) {
			el.onclick = function () {
				applyFix("enable_emails");
			};
		});

		rootEl.querySelectorAll('[data-action="enable-template"]').forEach(function (el) {
			el.onclick = function () {
				applyFix("enable_confirmation");
			};
		});

		rootEl.querySelectorAll('[data-action="open-from-editor"]').forEach(function (el) {
			el.onclick = function () {
				state.editingFromInline = true;
				render();
				const inp = document.getElementById("ur-eh-inline-from-input");
				if (inp) {
					inp.focus();
				}
			};
		});

		rootEl.querySelectorAll('[data-action="save-inline-from"]').forEach(function (el) {
			el.onclick = function () {
				const inp = document.getElementById("ur-eh-inline-from-input");
				const val = inp ? inp.value.trim() : "";
				if (!isValidEmailList(val)) {
					showToast(i18n.invalid_email, true);
					return;
				}
				applyFix("update_from_address", { from_address: val });
			};
		});

		rootEl.querySelectorAll('[data-action="cancel-inline-from"]').forEach(function (el) {
			el.onclick = function () {
				state.editingFromInline = false;
				render();
			};
		});

		const inlineFromInput = document.getElementById("ur-eh-inline-from-input");
		if (inlineFromInput) {
			inlineFromInput.onkeydown = function (e) {
				if (e.key === "Enter") {
					e.preventDefault();
					const val = inlineFromInput.value.trim();
					if (!isValidEmailList(val)) {
						showToast(i18n.invalid_email, true);
						return;
					}
					applyFix("update_from_address", { from_address: val });
				}
			};
		}

		rootEl.querySelectorAll("[data-fb]").forEach(function (el) {
			el.onclick = function () {
				const choice = el.getAttribute("data-fb");
				if (choice === "yes") {
					confirmTestEmailReceived();
				} else if (choice === "not_yet") {
					state.ehTestState = null;
					state.testSentWhileDisabled = false;
					state.report.incoming_issue = "not_received";
					render();
					window.scrollTo({ top: 0, behavior: "smooth" });
					focusHeaderVerdict();
				} else if (choice === "spam") {
					state.ehTestState = null;
					state.testSentWhileDisabled = false;
					state.report.incoming_issue = "spam";
					render();
					window.scrollTo({ top: 0, behavior: "smooth" });
					focusHeaderVerdict();
				}
			};
		});
	}

	document.addEventListener("visibilitychange", function () {
		if (document.visibilityState === "visible" && state.waitingForExternalSetup) {
			setTimeout(function () {
				refreshStatus({ manual: false, autoTriggered: true });
			}, 400);
		}
	});

	render();
})(jQuery);
