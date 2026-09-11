import { __ } from "@wordpress/i18n";
import { OverviewApiResponse } from "../types/analytics";
import { cn } from "../lib/utils";
import { MoveUp } from "./icons/move-up";
import { MoveDown } from "./icons/move-down";
import { Line, Pie } from "react-chartjs-2";
import { useAnalyticsFilters } from "../hooks/use-analytics-filters";
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	ArcElement,
	Title,
	Tooltip as ChartJSTooltip,
	Legend,
	Filler,
	Plugin
} from "chart.js";
import { Button } from "./ui/button";
import { urUtmUrl } from "../../utils/utm";
import {
	Select,
	SelectTrigger,
	SelectContent,
	SelectItem,
	SelectValue,
	SelectLabel,
	SelectGroup,
	SelectSeparator
} from "./ui/select";
import { useState } from "react";
import useLocalStorage from "../hooks/use-local-storage";

declare global {
	interface Window {
		Swal: typeof import("sweetalert2").default;
	}
}

const createLegendItem = (
	item: any,
	chart: any,
	className = "UR-Analytics-Chart__LegendItem"
) => {
	const li = document.createElement("li");
	li.className = className;
	if (item.hidden) {
		li.classList.add("UR-Analytics-Chart__LegendItem--hidden");
	}

	const boxSpan = document.createElement("span");
	boxSpan.className = "UR-Analytics-Chart__LegendColor";
	boxSpan.style.background = item.strokeStyle as string;
	boxSpan.style.borderColor = item.strokeStyle as string;
	boxSpan.style.borderWidth = item.lineWidth + "px";

	const textContainer = document.createElement("span");
	textContainer.className = "UR-Analytics-Chart__LegendText";
	textContainer.textContent = item.text;

	li.appendChild(boxSpan);
	li.appendChild(textContainer);

	li.onclick = (e) => {
		e.stopPropagation();
		if (typeof item.index !== "undefined") {
			chart.toggleDataVisibility(item.index);
		} else if (typeof item.datasetIndex !== "undefined") {
			chart.setDatasetVisibility(
				item.datasetIndex,
				!chart.isDatasetVisible(item.datasetIndex)
			);
		}
		chart.update();
	};

	return li;
};

const HTMLLegend: Plugin = {
	id: "HTMLLegend",
	afterUpdate(chart, _args, options) {
		const containerID = options.containerID;
		if (!containerID) return;

		const container = document.getElementById(containerID);
		if (!container) return;

		while (container.firstChild) {
			container.firstChild.remove();
		}

		const items =
			chart.options.plugins?.legend?.labels?.generateLabels?.(chart);
		if (!items || items.length <= 1) return;

		const legendList = document.createElement("ul");
		legendList.className = "UR-Analytics-Chart__LegendList";

		items.forEach((item) => {
			const legendItem = createLegendItem(item, chart);
			legendList.appendChild(legendItem);
		});

		container.appendChild(legendList);
	}
};

ChartJS.register(
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	ArcElement,
	Title,
	ChartJSTooltip,
	Legend,
	Filler,
	HTMLLegend
);

interface AnalyticsContentProps {
	overviewData: OverviewApiResponse;
}

const SUMMARY_OPTIONS: Array<{
	id: string;
	title: string;
}> = !!window?.__UR_ANALYTICS__?.memberships?.length
	? [
			{
				id: "total_revenue",
				title: __("Total Revenue", "user-registration")
			},
			{
				id: "new_members",
				title: __("New Members", "user-registration")
			},
			{
				id: "average_order_value",
				title: __("Average Order Value", "user-registration")
			},
			{
				id: "refunded_revenue",
				title: __("Refunded Revenue", "user-registration")
			}
	  ]
	: [
			{
				id: "new_members",
				title: __("New Members", "user-registration")
			},
			{
				id: "approved_members",
				title: __("Approved Members", "user-registration")
			},
			{
				id: "pending_members",
				title: __("Pending Members", "user-registration")
			},
			{
				id: "denied_members",
				title: __("Denied Members", "user-registration")
			}
	  ];

const CHART_LABELS = {
	0: __("Revenue Overview", "user-registration"),
	1: __("Members Overview", "user-registration"),
	2: __("Recurring Revenue", "user-registration"),
	3: __("Memberships Distribution", "user-registration")
};

type TimeUnit = "hour" | "day" | "week" | "month" | "year";
type PresetRange = "today" | "week" | "month" | "quarter" | "year" | "allTime";

interface DateRange {
	start: Date;
	end: Date;
	unit: TimeUnit;
}

class SeededRandom {
	private seed: number;

	constructor(seed: number) {
		this.seed = seed;
	}

	next(): number {
		this.seed = (this.seed * 9301 + 49297) % 233280;
		return this.seed / 233280;
	}
}
function generateLabels(
	startDate: Date,
	endDate: Date,
	unit: TimeUnit
): string[] {
	const labels: string[] = [];
	const current = new Date(startDate);
	const end = new Date(endDate);

	const formatters: Record<TimeUnit, (d: Date) => string> = {
		hour: (d) =>
			d.toLocaleTimeString("en-US", {
				hour: "2-digit",
				minute: "2-digit"
			}),
		day: (d) =>
			d.toLocaleDateString("en-US", {
				month: "short",
				day: "numeric"
			}),
		week: (d) => `Week ${getWeekNumber(d)}`,
		month: (d) =>
			d.toLocaleDateString("en-US", {
				month: "short",
				year: "numeric"
			}),
		year: (d) => d.getFullYear().toString()
	};

	const incrementors: Record<TimeUnit, (d: Date) => void> = {
		hour: (d) => d.setHours(d.getHours() + 1),
		day: (d) => d.setDate(d.getDate() + 1),
		week: (d) => d.setDate(d.getDate() + 7),
		month: (d) => d.setMonth(d.getMonth() + 1),
		year: (d) => d.setFullYear(d.getFullYear() + 1)
	};

	while (current <= end) {
		labels.push(formatters[unit](new Date(current)));
		incrementors[unit](current);
	}

	return labels;
}

function generateSeed(startDate: Date, endDate: Date, unit: TimeUnit): number {
	const dateString = `${startDate.getTime()}-${endDate.getTime()}-${unit}`;
	let hash = 0;
	for (let i = 0; i < dateString.length; i++) {
		const char = dateString.charCodeAt(i);
		hash = (hash << 5) - hash + char;
		hash = hash & hash;
	}
	return Math.abs(hash);
}

interface ChartDataset {
	label: string;
	data: number[];
	borderColor?: string;
	backgroundColor?: string | string[];
	tension?: number;
	borderWidth?: number;
}

interface ChartConfig {
	id: string;
	type: "line" | "bar" | "pie" | "doughnut";
	title: string;
	labels: string[];
	datasets: ChartDataset[];
}

export function generateAllCharts(
	startDate: Date,
	endDate: Date,
	unit: TimeUnit = "day"
): ChartConfig[] {
	const labels = generateLabels(startDate, endDate, unit);
	const dataPoints = labels.length;
	const seed = generateSeed(startDate, endDate, unit);

	const baseRevenue = 50000;
	const baseMembers = 1000;
	const growthRate = 0.05;

	const totalRevenue = generateTrendData(
		dataPoints,
		baseRevenue,
		growthRate,
		0.15,
		false,
		new SeededRandom(seed + 1)
	);
	const recurringRevenue = generateTrendData(
		dataPoints,
		baseRevenue * 0.7,
		growthRate,
		0.1,
		false,
		new SeededRandom(seed + 2)
	);
	const averageOrderValue = generateTrendData(
		dataPoints,
		150,
		growthRate * 0.3,
		0.08,
		false,
		new SeededRandom(seed + 3)
	);
	const newMembers = generateTrendData(
		dataPoints,
		50,
		growthRate,
		0.2,
		true,
		new SeededRandom(seed + 4)
	);
	const pendingMembers = generateTrendData(
		dataPoints,
		15,
		0,
		0.3,
		true,
		new SeededRandom(seed + 5)
	);
	const approvedMembers = generateTrendData(
		dataPoints,
		baseMembers,
		growthRate,
		0.05,
		true,
		new SeededRandom(seed + 6)
	);
	const arr = generateTrendData(
		dataPoints,
		baseRevenue * 12 * 0.7,
		growthRate,
		0.08,
		false,
		new SeededRandom(seed + 7)
	);
	const mrr = generateTrendData(
		dataPoints,
		baseRevenue * 0.7,
		growthRate,
		0.1,
		false,
		new SeededRandom(seed + 8)
	);

	const random = new SeededRandom(seed + 9);
	const basicMembers = Math.floor(baseMembers * 0.5 + random.next() * 100);
	const premiumMembers = Math.floor(baseMembers * 0.35 + random.next() * 80);
	const enterpriseMembers = Math.floor(
		baseMembers * 0.15 + random.next() * 50
	);

	return [
		{
			id: "revenue-overview",
			type: "line",
			title: "Revenue Overview",
			labels,
			datasets: [
				{
					label: "Total Revenue",
					data: totalRevenue,
					borderColor: "rgb(75, 192, 192)",
					backgroundColor: "rgba(75, 192, 192, 0.2)",
					tension: 0.2
				},
				{
					label: "Recurring Revenue",
					data: recurringRevenue,
					borderColor: "rgb(54, 162, 235)",
					backgroundColor: "rgba(54, 162, 235, 0.2)",
					tension: 0.2
				},
				{
					label: "Average Order Value",
					data: averageOrderValue,
					borderColor: "rgb(255, 205, 86)",
					backgroundColor: "rgba(255, 205, 86, 0.2)",
					tension: 0.2
				}
			]
		},

		{
			id: "members-overview",
			type: "line",
			title: "Members Overview",
			labels,
			datasets: [
				{
					label: "New Members",
					data: newMembers,
					borderColor: "rgb(153, 102, 255)",
					backgroundColor: "rgba(153, 102, 255, 0.2)",
					tension: 0.2
				},
				{
					label: "Pending Members",
					data: pendingMembers,
					borderColor: "rgb(255, 159, 64)",
					backgroundColor: "rgba(255, 159, 64, 0.2)",
					tension: 0.2
				},
				{
					label: "Approved Members",
					data: approvedMembers,
					borderColor: "rgb(75, 192, 192)",
					backgroundColor: "rgba(75, 192, 192, 0.2)",
					tension: 0.2
				}
			]
		},

		{
			id: "recurring-revenue",
			type: "line",
			title: "Recurring Revenue",
			labels,
			datasets: [
				{
					label: "Annual Recurring Revenue (ARR)",
					data: arr,
					backgroundColor: "rgba(54, 162, 235, 0.6)",
					borderColor: "rgb(54, 162, 235)",
					tension: 0.2
				},
				{
					label: "Monthly Recurring Revenue (MRR)",
					data: mrr,
					backgroundColor: "rgba(75, 192, 192, 0.6)",
					borderColor: "rgb(75, 192, 192)",
					tension: 0.2
				}
			]
		},

		{
			id: "membership-distribution",
			type: "pie",
			title: "Membership Distribution",
			labels: ["Basic", "Premium", "Enterprise"],
			datasets: [
				{
					label: "Members",
					data: [basicMembers, premiumMembers, enterpriseMembers],
					backgroundColor: ["#0088FE", "#00C49F", "#FFBB28"],
					// @ts-ignore
					borderColor: ["#0088FE", "#00C49F", "#FFBB28"]
				}
			]
		}
	];
}

function generateTrendData(
	points: number,
	baseValue: number,
	growthRate: number,
	variance: number,
	isInteger: boolean,
	random: SeededRandom
): number[] {
	const data: number[] = [];

	for (let i = 0; i < points; i++) {
		const trend = baseValue * (1 + growthRate * (i / points));
		const randomFactor = 1 + (random.next() - 0.5) * variance;
		const value = trend * randomFactor;

		data.push(
			isInteger ? Math.round(value) : Math.round(value * 100) / 100
		);
	}

	return data;
}

function getWeekNumber(d: Date): number {
	const date = new Date(d.getTime());
	date.setHours(0, 0, 0, 0);
	date.setDate(date.getDate() + 3 - ((date.getDay() + 6) % 7));
	const week1 = new Date(date.getFullYear(), 0, 4);
	return (
		1 +
		Math.round(
			((date.getTime() - week1.getTime()) / 86400000 -
				3 +
				((week1.getDay() + 6) % 7)) /
				7
		)
	);
}

const PRO_SUMMARY_OPTIONS = [
	{
		id: "new_payments_revenue",
		title: __("New Payments Revenue", "user-registration")
	},
	{
		id: "new_subscription_revenue",
		title: __("New Subscription Revenue", "user-registration")
	},
	{
		id: "mrr",
		title: __("Monthly Recurring Revenue (MRR)", "user-registration")
	},
	{
		id: "arr",
		title: __("Annual Recurring Revenue (ARR)", "user-registration")
	},
	{
		id: "new-orders",
		title: __("New Orders/Payments", "user-registration")
	},
	{
		id: "new-subscriptions",
		title: __("New Subscriptions", "user-registration")
	},
	{
		id: "refunds",
		title: __("Refunds", "user-registration")
	}
];

// async function firePricingPopup() {
// 	await window.Swal.fire({
// 		title: "",
// 		html: `
// 		<div class="ur-feature">
// 		<p >
// 		${__(
// 			"Get powerful analytics with revenue tracking, member insights, recurring revenue analysis, and advanced visualizations.",
// 			"user-registration"
// 		)}</p>
// 		<a href={urUtmUrl("https://wpuserregistration.com/upgrade/", { source: "ur-analytics", medium: "upgrade-link" })}>
// 			<svg
// 				xmlns="http://www.w3.org/2000/svg"
// 				width="24"
// 				height="24"
// 				viewBox="0 0 24 24"
// 				fill="none"
// 				stroke="currentColor"
// 				stroke-width="2"
// 				stroke-linecap="round"
// 				stroke-linejoin="round"
// 			>
// 				<path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z" />
// 				<path d="M5 21h14" />
// 			</svg>
// 			<span>
// 				${__("Upgrade to Pro", "user-registration")}
// 			</span>
// 		</a>
// 		</div>`,
// 		showCloseButton: true,
// 		showConfirmButton: false,
// 		customClass: {
// 			container: "UR-Feature-Gate-Popover"
// 		}
// 	});
// }

export const AnalyticsContent = ({ overviewData }: AnalyticsContentProps) => {
	const { filters } = useAnalyticsFilters();
	const { dateFrom, dateTo, unit } = filters;

	const charts = generateAllCharts(
		dateFrom
			? new Date(dateFrom)
			: new Date(new Date().setDate(new Date().getDate() - 30)),
		dateTo ? new Date(dateTo) : new Date(),
		unit ?? "day"
	);

	const [metricsState, setMetricsState] = useLocalStorage(
		"analytics-metrics",
		SUMMARY_OPTIONS.map((x) => x.id)
	);

	return (
		<div className="UR-Analytics-Content">
			<div className="UR-Analytics-Metrics">
				{SUMMARY_OPTIONS.map((metric, i) => {
					const data =
						overviewData?.[
							metricsState[i] as keyof OverviewApiResponse
						];

					if (!data) return null;

					const isPositive = data.count > data.previous;
					const isNegative = data.count < data.previous;

					return (
						<div
							key={JSON.stringify(metric)}
							className={"UR-Analytics-Metric"}
						>
							<div className="UR-Analytics-Metric__Header">
								<Select
									value={metricsState[i]}
									onValueChange={(v) => {
										if (
											SUMMARY_OPTIONS.some(
												(x) => x.id === v
											)
										) {
											setMetricsState((prev) => {
												const newMetrics = [...prev];
												newMetrics[i] = v;
												return newMetrics;
											});
										} else {
											// firePricingPopup();
										}
									}}
								>
									<SelectTrigger className="UR-Analytics-Metric__MetricSelector">
										<SelectValue />
									</SelectTrigger>
									<SelectContent
										align="start"
										collisionPadding={32}
										className="UR-UI-Select-Content"
									>
										{SUMMARY_OPTIONS.map((s) => (
											<SelectItem key={s.id} value={s.id}>
												{s.title}
											</SelectItem>
										))}
										<SelectSeparator />
										<SelectGroup className="UR-Analytics-Metric__MetricGroup-Pro">
											{/* <SelectLabel>
												<span>{__("More in Pro")}</span>
												<span>
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
														<path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z" />
														<path d="M5 21h14" />
													</svg>
												</span>
											</SelectLabel> */}
											{PRO_SUMMARY_OPTIONS.map((x) => (
												<SelectItem
													key={x.id}
													value={x.id}
													title={__(
														"Available in Pro",
														"user-registration"
													)}
												>
													<span>{x.title}</span>
													<span className="UR-Analytics-Metrics__Crown">
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
															<path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z" />
															<path d="M5 21h14" />
														</svg>
													</span>
												</SelectItem>
											))}
										</SelectGroup>
									</SelectContent>
								</Select>
							</div>
							<div className="UR-Analytics-Metric__Content">
								<div className="UR-Analytics-Metric__Value">
									{data.currency
										? new Intl.NumberFormat("en-US", {
												style: "currency",
												currency:
													window.__UR_ANALYTICS__
														.currency
										  }).format(data.count)
										: data.count}
									<span
										className={cn(
											"UR-Analytics-Metric__Delta",
											isPositive &&
												"UR-Analytics-Metric__Delta--positive",
											isNegative &&
												"UR-Analytics-Metric__Delta--negative"
										)}
									>
										{isPositive && (
											<MoveUp
												size="16"
												className="UR-Analytics-Metric__DeltaIcon"
											/>
										)}
										{isNegative && (
											<MoveDown
												size="16"
												className="UR-Analytics-Metric__DeltaIcon"
											/>
										)}
										{Math.abs(
											data.percentage_change
										).toFixed(1)}
										%
									</span>
								</div>
								<div className="UR-Analytics-Metric__Comparison">
									{__("vs.", "user-registration")}{" "}
									{data.currency
										? new Intl.NumberFormat("en-US", {
												style: "currency",
												currency:
													window.__UR_ANALYTICS__
														.currency
										  }).format(data.previous)
										: data.previous}{" "}
									{__("last period", "user-registration")}
								</div>
							</div>
						</div>
					);
				})}
			</div>
			<div className="UR-Analytics-Upgrade">
				<div className="UR-Analytics-Charts">
					{charts.map((chart, i) => {
						const height = i === 0 ? 400 : 300;
						const chartKey = `${i}-${dateFrom || "default"}-${
							dateTo || "default"
						}-${unit || "day"}`;
						const ChartComponent =
							chart.type === "line" ? Line : Pie;
						return (
							<div
								key={chartKey}
								className={cn(
									"UR-Analytics-Chart",
									"UR-Analytics-Chart--Multi",
									i === 0 && "UR-Analytics-Chart--Primary"
								)}
							>
								<div className="UR-Analytics-Chart__Header">
									<div className="UR-Analytics-Chart__ParentSelector">
										<span className="UR-Analytics-Metric__MetricTitle">
											{
												CHART_LABELS[
													i as keyof typeof CHART_LABELS
												]
											}
										</span>
									</div>
									<div
										id={`UR-Analytics-Legend-Container-${i}`}
										className={cn(
											"UR-Analytics-Chart__HTMLLegend",
											0 === i &&
												"UR-Analytics-Chart__HTMLLegend--Primary"
										)}
									></div>
								</div>
								<div
									className="UR-Analytics-Chart__Content"
									style={{ height: `${height}px` }}
								>
									<div
										className="UR-Analytics-Chart__ChartContainer"
										style={{ pointerEvents: "none" }}
									>
										<ChartComponent
											height={height}
											data={{
												labels: chart.labels,
												datasets: chart.datasets
											}}
											options={{
												responsive: true,
												maintainAspectRatio: false,
												plugins: {
													// @ts-ignore
													HTMLLegend: {
														containerID: `UR-Analytics-Legend-Container-${i}`
													},
													legend: {
														display: false
													},
													tooltip: {
														enabled: false
													}
												}
											}}
										/>
									</div>
								</div>
							</div>
						);
					})}
				</div>
				<div className="UR-Analytics-Charts__Overlay">
					<div className="UR-Analytics-Charts__Overlay-Content">
						<h3 className="UR-Analytics-Charts__Overlay-Title">
							{__(
								"Unlock Advanced Analytics",
								"user-registration"
							)}
						</h3>
						<div className="UR-Analytics-Charts__Overlay-Description">
							{__(
								"Get powerful analytics with revenue tracking, member insights, recurring revenue analysis, and advanced visualizations.",
								"user-registration"
							)}
						</div>
						<Button
							className="UR-Analytics-Charts__Overlay-Btn"
							asChild
						>
							<a
								target="_blank"
								href={urUtmUrl(
									"https://wpuserregistration.com/upgrade/",
									{
										source: "ur-analytics",
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
									<path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z" />
									<path d="M5 21h14" />
								</svg>
								<span>
									{__("Upgrade to Pro", "user-registration")}
								</span>
							</a>
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
};
