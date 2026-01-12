package models

import "time"

type RequestStatus string

const (
	StatusNew       RequestStatus = "new"
	StatusPosted    RequestStatus = "posted"
	StatusClaimed   RequestStatus = "claimed"
	StatusShopping  RequestStatus = "shopping"
	StatusDelivered RequestStatus = "delivered"
	StatusCancelled RequestStatus = "cancelled"
)

// Request represents a grocery request from a family
type Request struct {
	ID            int64
	OriginalText  string        // Spanish text as received
	TranslatedText string       // Formatted English shopping list
	Budget        string        // e.g., "$100 cash"
	Zone          string        // Neighborhood/area
	Status        RequestStatus
	ClaimedBy     int64         // Volunteer's Telegram user ID
	ClaimedByName string        // Volunteer's display name
	CreatedAt     time.Time
	UpdatedAt     time.Time
	DeliveredAt   *time.Time
}

// Volunteer represents an approved volunteer
type Volunteer struct {
	TelegramID   int64
	Username     string
	DisplayName  string
	IsApproved   bool
	IsCoordinator bool
	CreatedAt    time.Time
}

// Address is stored separately and deleted after delivery
type Address struct {
	RequestID int64
	Address   string
	CreatedAt time.Time
}
