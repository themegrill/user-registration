import { __ } from "@wordpress/i18n";
import React, { useState, useMemo } from "react";
import Main from "./components/Main";

import {
	ChakraProvider,
	Box,
	HStack,
	Text,
	Tabs,
	TabList,
	Tab,
	Button,
	Icon,
	Divider,
	VStack,
	Heading
} from "@chakra-ui/react";

const TabFilters = ({ onTabChange }) => {
	const filters = useMemo(
		() => [
			__("All", "user-registration"),
			__("Free", "user-registration"),
			__("Premium", "user-registration")
		],
		[]
	);

	return (
		<Tabs variant="unstyled" ml="auto" onChange={onTabChange}>
			<TabList
				bg="#f3f4f6"
				gap="2px"
				borderRadius="5px"
				p="4px"
				>
				{filters.map((label) => (
					<Tab
						key={label}
						_selected={{
							color: "#475bb2",
							bg:"white",
							boxShadow:"0 4px 24px 0 rgba(10,10,10, .06)",
						}}
						fontSize="14px"
						lineHeight="24px"
						color="#646970"
						fontWeight="medium"
						whiteSpace="nowrap"
						h="32px"
						borderRadius="4px"
						p="6px 16px"
						boxShadow="none !important"
						ml="0px !important"
					>
						{label}
					</Tab>
				))}
			</TabList>
		</Tabs>
	);
};

const App = () => {
	const [selectedTab, setSelectedTab] = useState(
		__("All", "user-registration")
	);

	// Handle tab changes
	const handleTabChange = (index) => {
		const filters = [
			__("All", "user-registration"),
			__("Free", "user-registration"),
			__("Premium", "user-registration")
		];
		setSelectedTab(filters[index]);
	};

	// Handle refresh button click
	const handleRefreshTemplates = () => {
		const url = new URL(window.location.href);
		url.searchParams.set("refresh", Date.now().toString());
		window.location.href = url.toString();
	};
	return (
		<ChakraProvider>
			<Box
				border={"1px solid #e1e1e1"}
				borderRadius={"13px"}
				backgroundColor="white"
				padding="24px"
				overflow="hidden"
			>
				<Box display="flex" flexDirection="column">
					<HStack
						align="center"
						bg="white"
						direction={{ base: "column", md: "row" }}
						borderBottom="1px solid #e1e1e1"
						margin="0 -24px"
						padding="0 24px 20px"
						boxShadow="none"
					>
						<HStack gap="16px">
							<Heading
								lineHeight="26px"
								fontWeight="600"
								margin="0"
								fontSize="18px"
								color="#383838"
							>
								{__("Add New Form", "user-registration")}
							</Heading>
							<Button
								style={{
									border: "1px solid #475bb2",
									borderRadius: "4px",
									transition: "all .3s ease-in-out"
								}}
								color="#475bb2"
								backgroundColor="#f6f7f7"
								fontWeight="500"
								height="34px"
								padding="0 16px"
								alignContent="center"
								fontSize="14px"
								lineHeight="20px"
								variant="outline"
								onClick={handleRefreshTemplates}
								textDecoration={"none"}
								boxSizing="border-box"
								width={{ base: "full", md: "auto" }}
								className="chakra-link "
								display={{ base: "none", md: "inline-flex" }} // Hide button on small screens
								gap="4px"
								_hover={{
									backgroundColor: "#475BB2",
									color: "white"
								}}
							>
								{__("Refresh Templates", "user-registration")}
							</Button>
						</HStack>
						<TabFilters onTabChange={handleTabChange} />
					</HStack>
					{/* Main Content Area */}
					<Box bg="white" rounded="md" gap="24px" boxShadow="none">
						<Main filter={selectedTab} />
					</Box>
				</Box>
			</Box>
		</ChakraProvider>
	);
};

export default App;
