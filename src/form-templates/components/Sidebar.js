import React, { useState, useCallback } from "react";
import {
	Box,
	VStack,
	HStack,
	Text,
	Spacer,
	Input,
	InputLeftElement,
	InputGroup,
	Badge,
	CardHeader,
	CardFooter,
	Button,
	Card,
	Heading
} from "@chakra-ui/react";
import { IoSearchOutline } from "react-icons/io5";
import debounce from "lodash.debounce";
import { __ } from "@wordpress/i18n";

const Sidebar = React.memo(
	({ categories, selectedCategory, onCategorySelect, onSearchChange }) => {
		const [searchTerm, setSearchTerm] = useState("");

		const debouncedSearchChange = useCallback(
			debounce((value) => {
				onSearchChange(value);
			}, 300),
			[onSearchChange]
		);

		const handleSearchChange = (e) => {
			const value = e.target.value;
			setSearchTerm(value);
			debouncedSearchChange(value);
		};

		const favorites = categories.find((cat) => cat.name === "Favorites");

		const orderedCategories =
			favorites && favorites.count > 0
				? [
						favorites,
						...categories.filter((cat) => cat.name !== "Favorites")
				  ]
				: categories;

		return (
			<Box 
				p="24px 24px 24px 0"
				maxWidth="320px"
				w="100%"
				boxSizing="border-box"
				>
				<InputGroup mb="26px">
					<InputLeftElement
						pointerEvents="none"
						padding="0px 0px 0px 8px"
					>
						<IoSearchOutline
							style={{ width: "18px", height: "18px" }}
							color="#737373"
						/>
					</InputLeftElement>
					<Input
						placeholder={__(
							"Search Templates",
							"user-registration"
						)}
						value={searchTerm}
						onChange={handleSearchChange}
						minHeight={"38px"}
						border={"1px solid #e1e1e1"}
						fontSize="14px"
						fontWeight="400"
						lineHeight="24px"
						color="#383838"
						borderRadius="4px"
						_focus={{
							borderColor: "#475bb2",
							outline: "none",
							boxShadow: "none"
						}}
						_placeholder={{
							color: "#737373"
						}}
					/>
				</InputGroup>

				<VStack align="stretch" gap="4px">
					{orderedCategories.map((category) => (
						<HStack
							key={category.name}
							p="10px 10px 10px 16px"
							bg="#f5f5f5"
							_hover={{
								bg: "#f5f5f5",
								"& > .badge": {
									bg:
										selectedCategory === category.name
											? "#FFFFFF"
											: "#FFFFFF"
								}
							}}
							borderRadius="md"
							cursor="pointer"
							justifyContent="space-between"
							bg={
								selectedCategory === category.name
									? "#f5f5f5"
									: "transparent"
							}
							onClick={() => onCategorySelect(category.name)}
						>
							<Text
								className="urm-category-list"
								color={selectedCategory === category.name ? "#475bb2" : "#4A5568"}
								fontWeight="medium"
								margin="0px"
								fontSize="14px"
								lineHeight="22px"
							>
								{category.name}
							</Text>
							<Badge
								className="badge"
								display="flex"
								alignItems="center"
								justifyContent="center"
								fontWeight="semibold"
								width="32px"
								height="26px"
								padding="0"
								borderRadius="4px"
								color={
									selectedCategory === category.name
										? "#475bb2"
										: ""
								}
								bg={
									selectedCategory === category.name
										? "white"
										: "#f5f5f5"
								}
							>
								{category.count}
							</Badge>
						</HStack>
					))}

					<Card
						align='center'
						marginTop="26px"
						padding="16px"
						bg="linear-gradient(135deg, rgba(64, 129, 240, 0.08), rgba(61,126,245,0.06))"
						border="1px solid rgba(64, 99, 240, 0.18)"
						borderRadius="9px"
						boxShadow="none"
						>
						<CardHeader padding="0px" marginBottom="12px">
							<Heading as="h5" fontSize="16px" color="#0f0f1a !important" lineHeight="26px" fontWeight="semibold"  padding="0px" margin="0px 0px 6px !important">
							{__("Can't find a template?", "user-registration")}
							</Heading>
							<Text fontSize="14px" lineHeight="22px !important" color="#6b6b85 !important" margin="0">{__('Request a custom template built for your needs.', 'user-registration')}</Text>
						</CardHeader>
						<CardFooter padding="0" width="100%">
						<a
							href="https://wpuserregistration.com/request-template"
							target="_blank"
							rel="noopener noreferrer"
							className="evf-custom-template"
							style={{
								display: "flex",
								alignItems: "center",
								justifyContent: "center",
								borderRadius:"4px",
								width: "100%",
								padding: "0px 12px",
								background: "linear-gradient(135deg, #4045f0, #3d71f5)",
								color: "white",
								fontSize: "14px",
								lineHeight: "24px",
								fontWeight: "medium",
								height: "34px"
							}}
							onFocus={(e) => {
								e.currentTarget.style.outline = "none";
								e.currentTarget.style.boxShadow = "none";
							}}
							>
								✦ {__("Request Template","user-registration")}
						</a>
						</CardFooter>
					</Card>
				</VStack>
			</Box>
		);
	}
);

export default Sidebar;
