# The Goody Basket — Testing Gameplan

A structured checklist for manually verifying every feature of the site before going live. Work through each section top to bottom. Mark each item ✅ as you confirm it works, or 🐛 if you find a bug (note what happened next to it).

---

## Prerequisites

Before testing, make sure:

- [ ] The database schema has been imported (`schema.sql` run in phpMyAdmin)
- [ ] `php/config.php` has valid DB credentials
- [ ] At least one admin account exists (run `php/setup_admin.php` once, then delete the file)
- [ ] At least one product and one pickup location are in the database
- [ ] The site is accessible locally (e.g. `http://localhost/TheGoodyBasket/`)

---

## 1. Page Load & Navigation

Test that the page loads correctly and all navigation links work.

- [ ] Page loads without console errors (open DevTools → Console)
- [ ] Google Fonts (Playfair Display, Lato) load correctly
- [ ] Nav logo scrolls to the hero section
- [ ] "Our Breads" nav link scrolls to the products section
- [ ] "Reviews" nav link scrolls to the reviews section
- [ ] "Our Story" nav link scrolls to the about section
- [ ] "Track Order" opens the order lookup modal
- [ ] "Order Now" nav link scrolls to the order section
- [ ] Cart badge is hidden when cart is empty; shows correct count when items are added

---

## 2. Products Grid (Customer View)

- [ ] Product cards render for all available products
- [ ] Each card shows: name, category badge (Regular/Specialty), description, allergen list, price, and Add to Cart button
- [ ] Hidden products do NOT appear in the customer grid
- [ ] If no products are available, an empty state message is shown

---

## 3. Cart

- [ ] Clicking "Add to Cart" on a product adds it and scrolls to the order section (first item only)
- [ ] Clicking "Add to Cart" again on the same product shows a toast "Added to cart ✓" and increments the quantity
- [ ] Cart badge in the nav updates correctly with the total loaf count
- [ ] Cart items display: name, category badge, price per item, quantity controls, subtotal, and remove button
- [ ] `+` button increases quantity; `−` button decreases it; hitting 0 removes the item
- [ ] `×` (remove) button removes the item entirely
- [ ] Order total updates correctly as quantities change
- [ ] Cart is empty state shows the basket icon and a "Browse our breads" link
- [ ] Cart persists across page refreshes for guest users (session-based)
- [ ] Cart persists for logged-in customers (account-based)

---

## 4. Order Form

### Happy Path

- [ ] Name, email, date, and location fields are present and required
- [ ] Phone field is optional but formats automatically as `(XXX) XXX-XXXX` as you type
- [ ] Only digits can be entered in the phone field (letters/symbols are blocked)
- [ ] Date picker minimum is set to tomorrow (no same-day orders)
- [ ] Selecting a Saturday or Sunday shows a warning: "We only offer pickup Tuesday through Friday"
- [ ] Selecting a blocked date shows a warning: "This date is unavailable"
- [ ] Location dropdown lists all active locations
- [ ] Notes field is optional and accepts free text
- [ ] Submitting a valid order with items in the cart shows the success modal with the Order ID (format: `TGB-XXXXXXXX`)
- [ ] After a successful order, the cart is cleared
- [ ] After closing the success modal, the page scrolls back to the order section

### Validation — expect error messages, not form submission

- [ ] Submit with empty name → error shown
- [ ] Submit with invalid email (e.g. `notanemail`) → error shown
- [ ] Submit with no date → error shown
- [ ] Submit with no location selected → error shown
- [ ] Submit with an empty cart → error shown
- [ ] Submit with a weekend date → error shown
- [ ] Submit when the selected date only has 1 loaf slot remaining and you're trying to order 2 → capacity error from server

### Capacity Enforcement

- [ ] Maximum 4 loaves total can be booked per pickup date across all orders
- [ ] Attempting to exceed 4 loaves on a date returns: "Only X loaf(ves) remaining on this date"

---

## 5. Order Lookup ("Track Order")

- [ ] "Track Order" link in nav opens the lookup modal
- [ ] Entering a valid Order ID + matching email displays order details: status, pickup date, location, items, total, and notes (if any)
- [ ] Status badge renders with the correct color (yellow = Pending, blue = Confirmed, green = Completed, red = Cancelled)
- [ ] Entering an incorrect Order ID or mismatched email shows: "Order not found"
- [ ] Submitting with empty fields shows: "Please enter both your Order ID and email address"
- [ ] Clicking the backdrop closes the modal

---

## 6. Reviews (Customer View)

### Viewing Reviews

- [ ] Approved reviews display in the reviews grid with: display name, date, star rating, review text, and a "Verified Order" pill showing pickup date and location
- [ ] Average rating and review count are shown above the grid
- [ ] If there are no approved reviews, an empty state is shown

### Submitting a Review

- [ ] "Leave a Review" button opens the review modal
- [ ] Entering a valid Order ID and email then clicking "Verify Order" confirms the order and shows a green verification strip
- [ ] Entering an invalid Order ID or wrong email shows an error in the verification strip
- [ ] Submitting without verifying first shows: "Please verify your order before submitting"
- [ ] Submitting without a name → error
- [ ] Submitting without a star rating → error
- [ ] Submitting without review text → error
- [ ] Successfully submitting a review shows a toast: "Review submitted! It will appear after approval."
- [ ] Attempting to submit a second review for the same order returns: "A review has already been submitted for this order"
- [ ] Clicking the backdrop closes the modal

---

## 7. Admin Login

- [ ] Clicking "Admin" (or the hidden trigger) opens the admin login modal
- [ ] Entering the correct admin email and password logs in and switches to the admin view
- [ ] Entering a wrong password shows: "Incorrect email or password"
- [ ] Entering credentials for a customer account (non-admin role) shows: "Not an admin account"
- [ ] Clicking the backdrop closes the login modal
- [ ] Pressing Enter in the password field submits the login form

---

## 8. Admin Panel — Dashboard Tab

- [ ] Stats grid shows: Total Orders, Pending, Confirmed, Today's Pickups, and Total Revenue (excl. cancelled)
- [ ] Revenue correctly excludes cancelled orders
- [ ] Orders table lists all orders with: Order ID, customer name + email + phone, items + notes, total, pickup date, location, status dropdown
- [ ] Status dropdown is color-coded (yellow/blue/green/red)
- [ ] Changing an order's status via the dropdown saves it and refreshes the dashboard
- [ ] Filter buttons (All, Pending, Confirmed, Completed, Cancelled) correctly filter the orders table
- [ ] Today's Pickups count reflects only orders with today's pickup date

---

## 9. Admin Panel — Calendar Tab

- [ ] Current month is shown with the correct day layout
- [ ] Weekend days (Sat/Sun) are grayed out and not clickable
- [ ] Past dates are grayed out and not clickable
- [ ] Today is highlighted
- [ ] Dates with orders show a `X/4` indicator (loaves booked vs. max)
- [ ] Blocked dates show an ✕ symbol
- [ ] Clicking an available future weekday toggles it blocked/unblocked and shows a toast confirmation
- [ ] Unblocking a date removes the ✕ and restores the date for customer ordering
- [ ] Prev/Next arrows navigate between months correctly
- [ ] A date blocked in the calendar is rejected when a customer tries to select it in the order form

---

## 10. Admin Panel — Products Tab

- [ ] All products (including hidden ones) are listed in the admin grid
- [ ] Each card shows: name, category badge, description, price, edit button, delete button, and available toggle
- [ ] "Add Product" button opens the product modal with blank fields
- [ ] Filling in name, category, price, description, availability, and allergens then saving adds the product
- [ ] The new product immediately appears in both the admin grid and the customer product grid
- [ ] Clicking the edit (✏️) button populates the modal with the product's current values
- [ ] Editing and saving a product updates it immediately in both views
- [ ] The "Available" toggle hides/shows the product for customers (toast confirms the change)
- [ ] Clicking delete (🗑️) prompts a confirmation dialog; confirming removes the product
- [ ] Saving a product with no name → toast: "Please enter a name and valid price"
- [ ] Saving with price ≤ 0 → toast: same error
- [ ] All six allergen checkboxes (Dairy, Tree Nuts, Egg, Peanut, Sesame, Soy) save and display correctly

---

## 11. Admin Panel — Locations Tab

- [ ] All locations are listed with name and address
- [ ] "Add Location" button opens the location modal with blank fields
- [ ] Filling in name and address then saving adds the location and it appears in the customer order dropdown
- [ ] Edit button populates the modal with current values; saving updates the location
- [ ] Delete button prompts confirmation; confirming removes the location
- [ ] Saving with empty name or address → toast: "Please fill in both fields"
- [ ] Deleted or inactive locations do not appear in the customer order form dropdown

---

## 12. Admin Panel — Reviews Tab

- [ ] Pending and approved review counts are shown
- [ ] Filter buttons (All, Pending, Approved) correctly filter the list
- [ ] Each review card shows: display name, status badge, star rating, date, review text, linked order ID + pickup details
- [ ] "Approve" button on a pending review approves it (it then appears on the customer-facing reviews section)
- [ ] "Delete" (🗑️) button on any review removes it permanently
- [ ] Approved review immediately visible on the customer-facing reviews section after approval

---

## 13. Admin Panel — Settings Tab

### Email Settings (EmailJS)

- [ ] Owner email, EJS User ID, Service ID, Template ID, and Customer Template ID fields are present
- [ ] "Save Email Settings" saves successfully and shows a toast
- [ ] Saved values persist after logging out and back in

### Password Change

- [ ] Entering the correct current password + a new password (8+ chars) updates it successfully
- [ ] Entering the wrong current password shows: "Current password is incorrect"
- [ ] Entering mismatched new passwords shows: "Passwords do not match"
- [ ] Entering a new password shorter than 8 chars shows: "Password must be at least 8 characters"
- [ ] After a successful change, the old password no longer works for login

---

## 14. Email Notifications (requires EmailJS configured)

- [ ] After a customer places an order, the owner receives an email with: customer name, email, phone, items, total, pickup date, location, notes, and Order ID
- [ ] The customer receives a confirmation email with the same details
- [ ] If EmailJS is not configured, orders still place successfully (email failure is silent)

---

## 15. Authentication — Customer Accounts

- [ ] A customer can register with a valid email and password (8+ chars)
- [ ] Registering with a duplicate email shows: "An account with this email already exists"
- [ ] A registered customer can log in
- [ ] A logged-in customer's cart persists in the database (not just session)
- [ ] Placing an order while logged in links the order to the customer's account
- [ ] `auth_me` returns the logged-in user on page load
- [ ] Logging out clears the session

---

## 16. Security & Edge Cases

- [ ] Visiting `php/api.php?action=orders_list` directly without an admin session returns a 401 error
- [ ] Visiting `php/api.php?action=products_add` directly without admin returns a 401 error
- [ ] `settings_public` endpoint only returns EmailJS config — not the full settings (e.g. no private keys)
- [ ] `phpinfo.php` and `setup_admin.php` should be deleted or blocked before going live
- [ ] Submitting an order for a product that has been made unavailable mid-session returns an error
- [ ] SQL injection: entering `'; DROP TABLE orders; --` in name/email fields is handled safely by PDO prepared statements (no crash, no data loss)
- [ ] XSS: entering `<script>alert(1)</script>` in a review text field does not execute on the reviews page (content is rendered as text)

---

## 17. Responsive / Mobile

- [ ] Nav links are accessible on mobile (hamburger or stacked layout)
- [ ] Product grid stacks to a single column on narrow screens
- [ ] Cart items are readable and controls are tappable on mobile
- [ ] Order form is fully usable on a phone (no fields cut off)
- [ ] Admin panel is functional on a tablet (tables may scroll horizontally — confirm they do)
- [ ] Modals are scrollable on small screens if content overflows

---

## 18. Pre-Launch Checklist

Before pointing a real domain at this:

- [ ] Delete or remove web access to `php/setup_admin.php` and `php/phpinfo.php`
- [ ] Set a strong admin password
- [ ] Uncomment the `Access-Control-Allow-Origin` header in `api.php` and set it to your actual domain
- [ ] Configure EmailJS and verify both email templates send correctly
- [ ] Add at least one real pickup location
- [ ] Add all products with correct prices and allergen info
- [ ] Test one full order end-to-end on the live server (place → confirm → complete)
- [ ] Verify the database backup strategy (e.g. scheduled MySQL dump)

---

*Last updated: 2026-04-13*
