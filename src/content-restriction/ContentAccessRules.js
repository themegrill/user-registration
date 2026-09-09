/**
 * External Dependencies
 */
import React, { useState, useEffect, useCallback } from "react";
import { __ } from "@wordpress/i18n";
import { getAllRules } from "./api/content-access-rules-api";
import RuleCard from "./components/rules/RuleCard";
import AddNewRuleModal from "./components/modals/AddNewRuleModal";
import { showError } from "./utils/notifications";
import {
	getURCRLocalizedData,
	getURCRData,
	isProAccess
} from "./utils/localized-data";
import apiFetch from "@wordpress/api-fetch";
import { urUtmUrl } from "../utils/utm";

/* global _URCR_DASHBOARD_ */
const { adminURL, assetsURL } =
	typeof _URCR_DASHBOARD_ !== "undefined" && _URCR_DASHBOARD_
		? _URCR_DASHBOARD_
		: {};

const ContentAccessRules = () => {
	const [rules, setRules] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState(null);
	const [expandedRules, setExpandedRules] = useState(new Set());
	const [openSettingsPanels, setOpenSettingsPanels] = useState(new Set());
	const [isModalOpen, setIsModalOpen] = useState(false);
	const [activeTab, setActiveTab] = useState("custom");
	const [highlightedRuleId, setHighlightedRuleId] = useState(null);
	const [hasAppliedHighlight, setHasAppliedHighlight] = useState(false);

	const urcrData = getURCRLocalizedData();
	const hasMultipleMemberships = getURCRData(
		"has_multiple_memberships",
		false
	);
	const isContentRestrictionEnabled = getURCRData(
		"is_content_restriction_enabled",
		false
	);

	const fetchRules = useCallback(() => {
		setIsLoading(true);
		setError(null);
		getAllRules()
			.then((data) => {
				if (data.success) {
					setRules(data.rules || []);
				} else {
					const errorMsg =
						data.message ||
						__("Failed to load rules", "user-registration");
					setError(errorMsg);
					showError(errorMsg);
				}
			})
			.catch((err) => {
				const errorMessage =
					err.message ||
					__(
						"An error occurred while loading rules",
						"user-registration"
					);
				setError(errorMessage);
				showError(errorMessage);
			})
			.finally(() => {
				setIsLoading(false);
			});
	}, []);

	useEffect(() => {
		fetchRules();
	}, [fetchRules]);

	// Get rule ID from URL params
	useEffect(() => {
		const urlParams = new URLSearchParams(window.location.search);
		const ruleId = urlParams.get("focus") || urlParams.get("id");
		if (ruleId) {
			const ruleIdNum = parseInt(ruleId, 10);
			if (!isNaN(ruleIdNum)) {
				setHighlightedRuleId(ruleIdNum);
			}
		}
	}, []);

	const membershipRules = rules.filter(
		(rule) => rule.rule_type === "membership"
	);
	const customRules = rules.filter(
		(rule) => rule.rule_type !== "membership" || !rule.rule_type
	);
	const shouldShowMembershipTab =
		hasMultipleMemberships && membershipRules.length > 1;
	const shouldShowCustomTab = isContentRestrictionEnabled;
	const shouldShowTabSwitcher = shouldShowMembershipTab;

	const currentRules = !isContentRestrictionEnabled
		? shouldShowMembershipTab
			? membershipRules
			: []
		: activeTab === "membership"
			? membershipRules
			: customRules;

	const [hasSetDefaultTab, setHasSetDefaultTab] = useState(false);
	useEffect(() => {
		if (!hasSetDefaultTab && !isLoading) {
			const urlParams = new URLSearchParams(window.location.search);
			const tabParam = urlParams.get("urcr_tab");

			if (tabParam === "membership" || tabParam === "custom") {
				setActiveTab(tabParam);
				setHasSetDefaultTab(true);
				return;
			}

			if (!isContentRestrictionEnabled) {
				setActiveTab("membership");
				setHasSetDefaultTab(true);
			} else if (shouldShowMembershipTab && membershipRules.length > 0) {
				setActiveTab("membership");
				setHasSetDefaultTab(true);
			} else if (!shouldShowMembershipTab) {
				setActiveTab("custom");
				setHasSetDefaultTab(true);
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [rules, isLoading, hasMultipleMemberships, isContentRestrictionEnabled]);

	useEffect(() => {
		if (!isLoading && rules.length > 0) {
			const allRuleIds = new Set(rules.map((rule) => rule.id));
			setExpandedRules(allRuleIds);
		}
	}, [rules, isLoading]);

	// Handle rule highlighting: switch tab, expand rule, and scroll to it (only once on initial load)
	useEffect(() => {
		if (
			highlightedRuleId &&
			!isLoading &&
			rules.length > 0 &&
			!hasAppliedHighlight
		) {
			const targetRule = rules.find(
				(rule) => rule.id === highlightedRuleId
			);
			if (targetRule) {
				// Determine which tab the rule belongs to
				const isMembershipRule = targetRule.rule_type === "membership";

				// Switch to the correct tab only on initial load
				if (isMembershipRule && shouldShowMembershipTab) {
					setActiveTab("membership");
				} else if (!isMembershipRule && shouldShowCustomTab) {
					setActiveTab("custom");
				}

				// Expand the rule
				setExpandedRules(
					(prev) => new Set([...prev, highlightedRuleId])
				);

				// Scroll to the rule after a short delay to allow DOM update
				setTimeout(() => {
					const ruleElement = document.querySelector(
						`.urcr-rule-card[data-rule-id="${highlightedRuleId}"]`
					);
					if (ruleElement) {
						ruleElement.scrollIntoView({
							behavior: "smooth",
							block: "center"
						});
					}
				}, 300);

				// Mark highlight as applied so it doesn't run again
				setHasAppliedHighlight(true);

				// Clear highlight after 3 seconds
				setTimeout(() => {
					setHighlightedRuleId(null);
				}, 3000);
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [highlightedRuleId, isLoading, rules, hasAppliedHighlight]);

	const handleToggleExpand = (ruleId) => {
		setExpandedRules((prev) => {
			const newSet = new Set(prev);
			if (newSet.has(ruleId)) {
				newSet.delete(ruleId);
			} else {
				newSet.add(ruleId);
			}
			return newSet;
		});
	};

	const handleToggleSettings = (ruleId) => {
		setOpenSettingsPanels((prev) => {
			const newSet = new Set(prev);
			if (newSet.has(ruleId)) {
				newSet.delete(ruleId);
			} else {
				newSet.add(ruleId);
			}
			return newSet;
		});
	};

	const handleRuleUpdate = (updatedRule) => {
		if (updatedRule) {
			setRules((prevRules) =>
				prevRules.map((rule) =>
					rule.id === updatedRule.id
						? { ...rule, ...updatedRule }
						: rule
				)
			);
		}
	};

	const handleRuleDelete = (ruleId) => {
		setRules((prevRules) => prevRules.filter((rule) => rule.id !== ruleId));
		setExpandedRules((prev) => {
			const newSet = new Set(prev);
			newSet.delete(ruleId);
			return newSet;
		});
		setOpenSettingsPanels((prev) => {
			const newSet = new Set(prev);
			newSet.delete(ruleId);
			return newSet;
		});
	};

	const handleRuleDuplicate = () => {
		fetchRules();
	};

	const handleRuleStatusUpdate = (ruleId, enabled) => {
		setRules((prevRules) =>
			prevRules.map((rule) =>
				rule.id === ruleId
					? {
							...rule,
							enabled,
							content: {
								...(rule.content || {}),
								enabled
							}
					  }
					: rule
			)
		);
	};

	const handleOpenModal = () => {
		setIsModalOpen(true);
	};

	const handleCloseModal = () => {
		setIsModalOpen(false);
	};

	const handleRuleCreated = (newRule) => {
		setRules((prevRules) => [newRule, ...prevRules]);
		setExpandedRules((prev) => new Set([...prev, newRule.id]));
		setActiveTab("custom");
	};

	const [moduleStatus, setModuleStatus] = useState(
		isContentRestrictionEnabled ? "inactive" : "active"
	);

	const activateAddon = async () => {
		setModuleStatus("activating");

		const ajaxUrl = urcrData?.ajax_url;
		const security = urcrData?.labels?.ajax_all_forms_nonce;

		if (!ajaxUrl || !security) {
			showError(
				__(
					"Activation failed: AJAX settings are missing. Please refresh the page and try again.",
					"user-registration"
				)
			);
			setModuleStatus("failed");
			return;
		}

		const formData = new FormData();
		formData.append(
			"action",
			"user_registration_activate_dependent_module"
		);
		formData.append("security", security);
		formData.append("slug", "user-registration-content-restriction");

		try {
			const res = await apiFetch({
				url: ajaxUrl,
				method: "POST",
				body: formData
			});

			if (res && res.success) {
				setModuleStatus("active");

				const url = new URL(window.location.href);
				url.searchParams.set("urcr_tab", "custom");
				window.location.href = url.toString();
			} else {
				setModuleStatus("failed");
			}
		} catch {
			setModuleStatus("failed");
		}
	};

	if (isLoading) {
		return (
			<div className="user-registration-content-restriction-viewer">
				<div className="urcr-loading-container">
					<span className="spinner is-active"></span>
				</div>
			</div>
		);
	}

	if (error && rules.length === 0) {
		return (
			<div className="user-registration-content-restriction-viewer">
				<div className="urcr-error-container">
					<p>{error}</p>
					<button
						className="button button-primary"
						onClick={fetchRules}
					>
						{__("Retry", "user-registration")}
					</button>
				</div>
			</div>
		);
	}

	return (
		<div className="user-registration-content-restriction-viewer">
			<div className="urcr-viewer-container">
				<div className="urcr-header">
					<h1>{__("Content Rules", "user-registration")}</h1>
					{isProAccess() &&
						isContentRestrictionEnabled &&
						activeTab === "custom" && (
							<button
								type="button"
								className="urcr-add-new-button"
								onClick={handleOpenModal}
							>
								<span className="dashicons dashicons-plus-alt2"></span>
								{__("Add New", "user-registration")}
							</button>
						)}
				</div>

				{shouldShowTabSwitcher && (
					<div className="urcr-tabs">
						<button
							type="button"
							className={`urcr-tab ${
								activeTab === "membership"
									? "urcr-tab-active"
									: ""
							}`}
							onClick={() => setActiveTab("membership")}
						>
							{__("Membership Rules", "user-registration")}
						</button>
						<button
							type="button"
							className={`urcr-tab ${
								activeTab === "custom" ? "urcr-tab-active" : ""
							}`}
							onClick={() => setActiveTab("custom")}
						>
							{__("Custom Rules", "user-registration")}
						</button>
					</div>
				)}

				<AddNewRuleModal
					isOpen={isModalOpen}
					onClose={handleCloseModal}
					onCreateSuccess={handleRuleCreated}
				/>

				{activeTab === "custom" && !isContentRestrictionEnabled ? (
					<>
						<div className="ur-feature ur-feature--locked">
							<div
								style={{ pointerEvents: "none" }}
								dangerouslySetInnerHTML={{
									__html: `<div class="user-registration-card ur-mb-2 urcr-rule-card " style="margin-bottom: 20px !important;" data-rule-id="26"><div class="user-registration-card__header ur-d-flex ur-align-items-center ur-p-5 integration-header-info accordion active"><div class="integration-detail urcr-integration-detail"><h3 class="user-registration-card__title">Access Control</h3><span class="urcr-separator"> | </span><span class="urcr-rule-id">ID: 26</span><span class="urcr-separator"> | </span><span class="urcr-status-label">Status :</span><div class="ur-toggle-section"><span class="user-registration-toggle-form"><input type="checkbox" checked=""><span class="slider round"></span></span></div></div><div class="integration-action urcr-integration-action"><div class="urcr-membership-tabs"><button class="urcr-tab-button urcr-tab-active" type="button">Rule</button><button class="urcr-tab-button " type="button">Settings</button></div><div class="urcr-menu-wrapper"><button class="urcr-menu-toggle button-link " type="button" aria-label="More options"><span class="dashicons dashicons-ellipsis"></span></button></div><svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1 urcr-icon-active" style="transform: rotate(180deg); transition: transform 0.3s; cursor: pointer;"><polyline points="6 9 12 15 18 9"></polyline></svg></div></div><div class="user-registration-card__body ur-p-3 integration-body-info" style="display: block;"><div class="urcr-tab-content urcr-tab-content-active"><div class="urcr-rule-content-panel"><div class="urcr-content-group "><div class="urcr-rule-body ur-p-2"><div class="urcr-condition-row-parent"><div class="urcr-conditions-list "><div class="urcr-condition-wrapper"><div class="urcr-condition-row ur-d-flex ur-mt-2 ur-align-items-start"><div class="urcr-condition-only ur-d-flex ur-align-items-start"><div class="urcr-condition-selection-section ur-d-flex ur-align-items-center ur-g-4"><div class="urcr-condition-field-name"><select class="components-select-control__input urcr-condition-value-input"><option value="user_state">User State</option></select></div><div class="urcr-condition-operator"><span>is</span></div><div class="urcr-condition-value"><div class="urcr-checkbox-radio-group"><label class="urcr-checkbox-radio-option is-checked"><input type="radio" name="urcr-radio-user_state-x1768832321664" class="urcr-checkbox-radio-input" value="logged-in"><span class="urcr-checkbox-radio-label">Logged In</span></label><label class="urcr-checkbox-radio-option "><input type="radio" name="urcr-radio-user_state-x1768832321664" class="urcr-checkbox-radio-input" value="logged-out"><span class="urcr-checkbox-radio-label">Logged Out</span></label></div></div></div></div></div><button type="button" class="button button-link-delete" aria-label="Remove condition"><span class="dashicons dashicons-no-alt"></span></button></div></div><div class="urcr-target-selection-section ur-d-flex ur-align-items-start"><div class="urcr-condition-value-input-wrapper urcr-access-content"><div class="urcr-dropdown-wrapper urcr-access-control-dropdown-wrapper"><button type="button" class="urcr-dropdown-button urcr-access-control-button urcr-condition-value-input"><span class="urcr-dropdown-button-text">Access</span><span class="urcr-dropdown-button-arrow dashicons dashicons-arrow-down-alt2"></span></button></div></div><span class="urcr-arrow-icon" aria-hidden="true"></span><div class="ur-d-flex ur-flex-column"><div class="urcr-dropdown-wrapper urcr-content-dropdown-wrapper"><button type="button" class="urcr-dropdown-button button urcr-add-content-button"><span class="dashicons dashicons-plus-alt2"></span>Content</button></div></div></div></div><div class="urcr-buttons-wrapper" style="display: flex; gap: 10px; margin-top: 10px;"><div class="urcr-dropdown-wrapper urcr-condition-dropdown-wrapper"><button type="button" class="urcr-dropdown-button button urcr-add-condition-button"><span class="dashicons dashicons-plus-alt2"></span>Condition</button></div></div></div></div><div class="urcr-rule-actions"><button class="urcr-save-rule-btn button button-primary" type="button" data-rule-id="26">Save</button></div></div></div></div></div><div class="user-registration-card ur-mb-2 urcr-rule-card " data-rule-id="26"><div class="user-registration-card__header ur-d-flex ur-align-items-center ur-p-5 integration-header-info accordion active"><div class="integration-detail urcr-integration-detail"><h3 class="user-registration-card__title">Access Control</h3><span class="urcr-separator"> | </span><span class="urcr-rule-id">ID: 26</span><span class="urcr-separator"> | </span><span class="urcr-status-label">Status :</span><div class="ur-toggle-section"><span class="user-registration-toggle-form"><input type="checkbox" checked=""><span class="slider round"></span></span></div></div><div class="integration-action urcr-integration-action"><div class="urcr-membership-tabs"><button class="urcr-tab-button urcr-tab-active" type="button">Rule</button><button class="urcr-tab-button " type="button">Settings</button></div><div class="urcr-menu-wrapper"><button class="urcr-menu-toggle button-link " type="button" aria-label="More options"><span class="dashicons dashicons-ellipsis"></span></button></div><svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="css-i6dzq1 urcr-icon-active" style="transform: rotate(180deg); transition: transform 0.3s; cursor: pointer;"><polyline points="6 9 12 15 18 9"></polyline></svg></div></div><div class="user-registration-card__body ur-p-3 integration-body-info" style="display: block;"><div class="urcr-tab-content urcr-tab-content-active"><div class="urcr-rule-content-panel"><div class="urcr-content-group "><div class="urcr-rule-body ur-p-2"><div class="urcr-condition-row-parent"><div class="urcr-conditions-list "><div class="urcr-condition-wrapper"><div class="urcr-condition-row ur-d-flex ur-mt-2 ur-align-items-start"><div class="urcr-condition-only ur-d-flex ur-align-items-start"><div class="urcr-condition-selection-section ur-d-flex ur-align-items-center ur-g-4"><div class="urcr-condition-field-name"><select class="components-select-control__input urcr-condition-value-input"><option value="user_state">User State</option></select></div><div class="urcr-condition-operator"><span>is</span></div><div class="urcr-condition-value"><div class="urcr-checkbox-radio-group"><label class="urcr-checkbox-radio-option is-checked"><input type="radio" name="urcr-radio-user_state-x1768832321664" class="urcr-checkbox-radio-input" value="logged-in"><span class="urcr-checkbox-radio-label">Logged In</span></label><label class="urcr-checkbox-radio-option "><input type="radio" name="urcr-radio-user_state-x1768832321664" class="urcr-checkbox-radio-input" value="logged-out"><span class="urcr-checkbox-radio-label">Logged Out</span></label></div></div></div></div></div><button type="button" class="button button-link-delete" aria-label="Remove condition"><span class="dashicons dashicons-no-alt"></span></button></div></div><div class="urcr-target-selection-section ur-d-flex ur-align-items-start"><div class="urcr-condition-value-input-wrapper urcr-access-content"><div class="urcr-dropdown-wrapper urcr-access-control-dropdown-wrapper"><button type="button" class="urcr-dropdown-button urcr-access-control-button urcr-condition-value-input"><span class="urcr-dropdown-button-text">Access</span><span class="urcr-dropdown-button-arrow dashicons dashicons-arrow-down-alt2"></span></button></div></div><span class="urcr-arrow-icon" aria-hidden="true"></span><div class="ur-d-flex ur-flex-column"><div class="urcr-dropdown-wrapper urcr-content-dropdown-wrapper"><button type="button" class="urcr-dropdown-button button urcr-add-content-button"><span class="dashicons dashicons-plus-alt2"></span>Content</button></div></div></div></div><div class="urcr-buttons-wrapper" style="display: flex; gap: 10px; margin-top: 10px;"><div class="urcr-dropdown-wrapper urcr-condition-dropdown-wrapper"><button type="button" class="urcr-dropdown-button button urcr-add-condition-button"><span class="dashicons dashicons-plus-alt2"></span>Condition</button></div></div></div></div><div class="urcr-rule-actions"><button class="urcr-save-rule-btn button button-primary" type="button" data-rule-id="26">Save</button></div></div></div></div></div>`
								}}
							/>
							<div className="ur-feature__overlay">
								<div className="ur-feature__overlay-content">
									<h3 className="ur-feature__title">
										{__(
											"Unlock Custom Rules",
											"user-registration"
										)}
									</h3>
									<div className="ur-feature__desc">
										{__(
											"Unlock the ability to add custom rules, based on various user attributes like Roles, User fields, Registration sources and More."
										)}
									</div>
									{isProAccess() ? (
										<button
											type="button"
											onClick={activateAddon}
											disabled={
												moduleStatus === "activating"
											}
											className="ur-feature__btn"
										>
											{__(
												"Activate Addon",
												"user-registration"
											)}
										</button>
									) : (
										<a
											className="ur-feature__btn"
											target="_blank"
											href={urUtmUrl(
												"https://wpuserregistration.com/upgrade/",
												{
													source: "ur-membership-create",
													medium: "upgrade-link"
												}
											)}
										>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												width="24"
												height="24"
												viewBox="0 0 24 24"
												fill="none"
												stroke="currentColor"
												strokeWidth="2"
												strokeLinecap="round"
												strokeLinejoin="round"
											>
												<path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path>
												<path d="M5 21h14"></path>
											</svg>
											<span>
												{__(
													"Upgrade to Pro",
													"user-registration"
												)}
											</span>
										</a>
									)}
								</div>
							</div>
						</div>
					</>
				) : (
					<>
						{currentRules.length === 0 ? (
							<div className="user-registration-card ur-text-center urcr-no-rules">
								<img
									src={`${
										assetsURL || ""
									}images/empty-table.png`}
									alt={__(
										"No rules found",
										"user-registration"
									)}
								/>
							</div>
						) : (
							<div className="urcr-rules-list">
								{currentRules.map((rule) => (
									<RuleCard
										key={rule.id}
										rule={rule}
										isExpanded={expandedRules.has(rule.id)}
										isSettingsOpen={openSettingsPanels.has(
											rule.id
										)}
										isHighlighted={
											highlightedRuleId === rule.id
										}
										onToggleExpand={() =>
											handleToggleExpand(rule.id)
										}
										onToggleSettings={() =>
											handleToggleSettings(rule.id)
										}
										onRuleUpdate={handleRuleUpdate}
										onRuleStatusUpdate={
											handleRuleStatusUpdate
										}
										onRuleDelete={handleRuleDelete}
										onRuleDuplicate={handleRuleDuplicate}
									/>
								))}
							</div>
						)}
					</>
				)}
			</div>
		</div>
	);
};

export default ContentAccessRules;
