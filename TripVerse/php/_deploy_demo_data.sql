-- TripVerse — demo data for the live (InfinityFree) database.
-- Run once in phpMyAdmin against the live DB. Safe to re-run.
--
-- Why this exists: 49 of the 51 hotels had owner_id = '' (no owner), so the
-- supplier panel had nothing to manage and the supplier demo looked empty.
-- This hands six hotels that already carry bookings to supplier OWN002
-- (sallu@tripverse.com), across Jakarta, Depok and Tangerang.
--
-- NOTE: account passwords are deliberately NOT set here — this file is
-- committed to the repo. Reset demo passwords from phpMyAdmin instead.

UPDATE hotel
SET owner_id = 'OWN002'
WHERE hotel_id IN ('JKT002','JKT004','JKT005','JKT009','DPK001','TGR001');

-- Verify: expect OWN001 = 1 hotel, OWN002 = 7 hotels.
SELECT h.owner_id,
       COUNT(*) AS hotels,
       (SELECT COUNT(*)
          FROM booking_hotel b
          JOIN hotel h2 ON b.hotel_id = h2.hotel_id
         WHERE h2.owner_id = h.owner_id) AS bookings
FROM hotel h
WHERE h.owner_id IN ('OWN001','OWN002')
GROUP BY h.owner_id;
